<?php

namespace App\Services\Databackd\Gaps\BankImport;

use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinCounterpartyMapping;
use Illuminate\Support\Collection;

/**
 * Chooses the account (revenue 7xx for money in, expense 6xx for money out) of a bank transaction from the rules in
 * fin_counterparty_mappings.
 *
 * A rule matches when its pattern (case-insensitive, spaces collapsed) is found in / equals the counterparty name, the
 * counterparty IBAN or the description of the transaction; `direction` limits it to credit (money in) or debit (money out).
 * A rule is applied only when its account fits the direction (credit => revenue account, debit => expense account), so a
 * rule with direction "any" and a revenue account never books a payment.
 *
 * The most specific rule wins: exact before contains, a rule for the legal entity before a rule for all entities,
 * a direction before "any", IBAN before name before description, then the longer pattern; on a tie the newest rule.
 */
class CounterpartyMatcher
{

    const ACCOUNT_TYPE_BY_DIRECTION = ['credit' => 'revenue', 'debit' => 'expense'];

    protected int $companyId;
    protected ?int $entityId;

    /** @var Collection<int, FinCounterpartyMapping>|null */
    protected ?Collection $rules = null;
    /** @var array<int, array{id: int, code: string, name: string, type: string, is_active: bool}>|null */
    protected ?array $accounts = null;

    public function __construct(?int $companyId = null, ?int $entityId = null)
    {
        $this->companyId = $companyId ?: company()->id;
        $this->entityId = $entityId ?: null;
    }

    /** Forget the loaded rules (after rules were created or changed). */
    public function reload(): static
    {
        $this->rules = null;
        $this->accounts = null;

        return $this;
    }

    /**
     * @param array $transaction a transaction of the parsed statement (counterparty_name, counterparty_iban, description, direction)
     *
     * @return array{rule_id: int, account_id: int, counterparty_name: string|null, score: int, matched_on: string}|null
     */
    public function match(array $transaction): ?array
    {
        $direction = ($transaction['direction'] ?? null) === 'debit' ? 'debit' : 'credit';
        $name = self::normalizeText($transaction['counterparty_name'] ?? null);
        $iban = Iban::normalize($transaction['counterparty_iban'] ?? null);
        $text = self::normalizeText($transaction['description'] ?? null);
        $best = null;

        foreach ($this->loadRules() as $rule) {
            $account = $this->loadAccounts()[$rule->account_id] ?? null;

            if (!$account || !$account['is_active'] || $account['type'] !== self::ACCOUNT_TYPE_BY_DIRECTION[$direction]) {
                continue;
            }

            if ($rule->direction !== 'any' && $rule->direction !== $direction) {
                continue;
            }

            if ($rule->entity_id && $this->entityId && (int)$rule->entity_id !== $this->entityId) {
                continue;
            }

            if ($rule->entity_id && !$this->entityId) {
                continue; // a rule of one legal entity needs the entity to be known
            }

            $matchedOn = $this->matchedField($rule, $name, $iban, $text);

            if ($matchedOn === null) {
                continue;
            }

            $score = $this->score($rule, $matchedOn);

            if ($best === null || $score > $best['score'] || ($score === $best['score'] && $rule->id > $best['rule_id'])) {
                $best = [
                    'rule_id' => (int)$rule->id,
                    'account_id' => (int)$rule->account_id,
                    'counterparty_name' => $rule->counterparty_name ?: null,
                    'score' => $score,
                    'matched_on' => $matchedOn,
                ];
            }
        }

        return $best;
    }

    /**
     * Match a list of transactions.
     *
     * @return array<int, array|null> same keys as $transactions
     */
    public function matchAll(array $transactions): array
    {
        $result = [];

        foreach ($transactions as $key => $transaction) {
            $result[$key] = $this->match($transaction);
        }

        return $result;
    }

    // ------------------------------------------------------------------ matching

    /** 'iban' | 'name' | 'text' - the field the rule matched, or null. */
    protected function matchedField(FinCounterpartyMapping $rule, ?string $name, ?string $iban, ?string $text): ?string
    {
        $pattern = self::normalizeText($rule->pattern);

        if ($pattern === null) {
            return null;
        }

        $patternIban = Iban::normalize($rule->pattern);
        $looksLikeIban = $patternIban !== null && preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $patternIban) && !str_contains(trim($rule->pattern), ' ')
            || Iban::isValid($rule->pattern);

        if ($looksLikeIban) {
            if ($iban === null) {
                return null;
            }

            $hit = $rule->match_type === 'exact' ? $iban === $patternIban : str_contains($iban, $patternIban);

            return $hit ? 'iban' : null;
        }

        if ($rule->match_type === 'exact') {
            if ($name !== null && $name === $pattern) {
                return 'name';
            }

            return $text !== null && $text === $pattern ? 'text' : null;
        }

        if ($name !== null && str_contains($name, $pattern)) {
            return 'name';
        }

        if ($iban !== null && str_contains($iban, strtoupper(str_replace(' ', '', $rule->pattern)))) {
            return 'iban';
        }

        return $text !== null && str_contains($text, $pattern) ? 'text' : null;
    }

    protected function score(FinCounterpartyMapping $rule, string $matchedOn): int
    {
        $score = $rule->match_type === 'exact' ? 100000 : 0;
        $score += $rule->entity_id ? 20000 : 0;
        $score += $rule->direction !== 'any' ? 10000 : 0;
        $score += ['iban' => 3000, 'name' => 2000, 'text' => 1000][$matchedOn];
        $score += min(mb_strlen((string)$rule->pattern), 500);

        return $score;
    }

    // ------------------------------------------------------------------ data

    /** @return Collection<int, FinCounterpartyMapping> */
    protected function loadRules(): Collection
    {
        return $this->rules ??= FinCounterpartyMapping::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->get();
    }

    protected function loadAccounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = [];

            FinAccount::withoutGlobalScopes()->where('company_id', $this->companyId)
                ->get(['id', 'code', 'name', 'type', 'is_active'])
                ->each(function ($account) {
                    $this->accounts[(int)$account->id] = [
                        'id' => (int)$account->id,
                        'code' => (string)$account->code,
                        'name' => (string)$account->name,
                        'type' => $account->type,
                        'is_active' => (bool)$account->is_active,
                    ];
                });
        }

        return $this->accounts;
    }

    /** Lower case, spaces collapsed, trimmed; null when empty. */
    public static function normalizeText(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim(preg_replace('/\s+/u', ' ', mb_strtolower($value)));

        return $value === '' ? null : $value;
    }

}

<?php

namespace App\Services\Databackd\Gaps\BankImport;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBankStatement;
use App\Models\Databackd\FinCounterpartyMapping;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Data\FinEntryImporter;
use App\Services\Databackd\Gaps\FxRates\FxRateService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Review and import of parsed bank statements (see BankStatementParser for the structure) into fin_entries.
 *
 * Model of the records:
 *  - every transaction is a flow (kind = 'flow') with source 'bank_statement' on a revenue account (money in) or an expense
 *    account (money out): the account comes from the counterparty rules (CounterpartyMatcher), from a choice made in the
 *    review, or from the default account of the direction; a transaction without an account is skipped;
 *  - the closing balance of the statement is a balance (kind = 'balance') of the cash account (503) at the end of the period
 *    and the opening balance a balance at the day before the period - the opening one only when the legal entity has no
 *    balance of that account at (or before) that date yet. So the cash in the balance sheet equals the bank statement;
 *  - the header of the statement goes to fin_bank_statements; all rows share one fin_import_batches record
 *    (source 'mt940' | 'camt053'), so the whole import can be undone.
 *
 * Duplicate protection (per statement; undone imports do not count):
 *  - the same file (sha256, kept in the batch mapping) or the same IBAN + period + statement number (or the same period and the
 *    same opening/closing balance and transaction count) -> blocked, nothing is imported;
 *  - another file of the same IBAN with an overlapping period -> allowed only after an explicit confirmation, and the
 *    transactions that already exist (same date, amount, currency and reference) are unticked by default.
 *
 * Tenant: all queries use the explicit company id given to the constructor (works in commands and seeders).
 */
class BankStatementImporter
{

    const SOURCE = 'bank_statement';

    /** Account code of the account the balances of the bank statements are booked to. */
    const CASH_CODE = '503';

    /** Accounts used for transactions without a rule: other revenue / other expenses. */
    const DEFAULT_CREDIT_CODE = '709';
    const DEFAULT_DEBIT_CODE = '609';

    /** Short-term bank loans: where a negative (overdraft) closing balance is booked. */
    const OVERDRAFT_CODE = '498';

    const MAX_ERRORS = 500;

    protected int $companyId;
    protected ?Company $company = null;
    protected ?string $baseCurrency = null;
    protected ?array $accounts = null;
    protected ?FxRateService $fx = null;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?: company()->id;
    }

    // ------------------------------------------------------------------ one-call helper

    /**
     * Parse a file and import it with the default decisions (rules, then the default accounts). Used by seeders and tests.
     *
     * @param array $options see import()
     */
    public function importFile(string $path, int $entityId, array $options = []): FinImportBatch
    {
        $parsed = (new BankStatementParser())->parseFile($path, $options['file_name'] ?? basename($path));

        return $this->import($parsed, $entityId, $options + ['file_name' => basename($path)]);
    }

    // ------------------------------------------------------------------ accounts

    /**
     * Accounts a transaction of the direction can be booked to: revenue accounts for 'credit' (money in), expense for 'debit'.
     *
     * @return array<int, array{id: int, code: string, name: string, type: string, subtype: string|null}>
     */
    public function accountsFor(string $direction): array
    {
        $type = CounterpartyMatcher::ACCOUNT_TYPE_BY_DIRECTION[$direction] ?? 'revenue';

        return array_values(array_filter($this->accounts(), fn($a) => $a['type'] === $type && $a['is_active']));
    }

    /** Cash accounts the balances can be booked to (asset accounts with the subtype 'cash'). */
    public function cashAccounts(): array
    {
        return array_values(array_filter($this->accounts(), fn($a) => $a['type'] === 'asset' && $a['subtype'] === 'cash' && $a['is_active']));
    }

    public function defaultCashAccountId(): ?int
    {
        $cash = $this->cashAccounts();

        foreach ($cash as $account) {
            if ($account['code'] === self::CASH_CODE) {
                return $account['id'];
            }
        }

        return $cash[0]['id'] ?? null;
    }

    /** @return array{credit: int|null, debit: int|null} suggested accounts for transactions without a rule */
    public function defaultAccountIds(): array
    {
        $pick = function (string $direction, string $code): ?int {
            $list = $this->accountsFor($direction);

            foreach ($list as $account) {
                if ($account['code'] === $code) {
                    return $account['id'];
                }
            }

            foreach ($list as $account) {
                if ($account['subtype'] === 'other') {
                    return $account['id'];
                }
            }

            return null;
        };

        return ['credit' => $pick('credit', self::DEFAULT_CREDIT_CODE), 'debit' => $pick('debit', self::DEFAULT_DEBIT_CODE)];
    }

    /** @return array<int, array{id: int, code: string, name: string, type: string, subtype: string|null, is_active: bool}> */
    protected function accounts(): array
    {
        if ($this->accounts === null) {
            $this->accounts = [];

            FinAccount::withoutGlobalScopes()->where('company_id', $this->companyId)->orderBy('code')
                ->get(['id', 'code', 'name', 'type', 'subtype', 'is_active'])
                ->each(function ($account) {
                    $this->accounts[(int)$account->id] = [
                        'id' => (int)$account->id,
                        'code' => (string)$account->code,
                        'name' => (string)$account->name,
                        'type' => $account->type,
                        'subtype' => $account->subtype,
                        'is_active' => (bool)$account->is_active,
                    ];
                });
        }

        return $this->accounts;
    }

    protected function accountByCode(string $code): ?array
    {
        foreach ($this->accounts() as $account) {
            if ($account['code'] === $code) {
                return $account;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------ review

    /**
     * Everything the review screen shows, computed without writing anything.
     *
     * @return array{
     *   statements: array<int, array>, transactions: int, receipts: float, payments: float, unmatched: int,
     *   duplicate_rows: int, has_overlap: bool, importable: int, defaults: array
     * }
     */
    public function review(array $parsed, ?int $entityId): array
    {
        $matcher = new CounterpartyMatcher($this->companyId, $entityId);
        $fileHash = $parsed['file_hash'] ?? null;
        $cashId = $this->defaultCashAccountId();
        $index = 0;
        $statements = [];
        $totals = ['transactions' => 0, 'receipts' => 0.0, 'payments' => 0.0, 'unmatched' => 0, 'duplicate_rows' => 0, 'importable' => 0];
        $hasOverlap = false;

        foreach ($parsed['statements'] as $position => $statement) {
            $duplicate = $this->findDuplicate($statement, $fileHash);
            $existing = $entityId ? $this->existingTransactionKeys($statement, $entityId) : [];
            $rows = [];
            $receipts = $payments = 0.0;
            $unmatched = $duplicates = 0;

            foreach ($statement['transactions'] as $transaction) {
                $match = $matcher->match($transaction);
                $key = $this->transactionKey($transaction);
                $isDuplicate = false;

                if (($existing[$key] ?? 0) > 0) {
                    $existing[$key]--;
                    $isDuplicate = true;
                    $duplicates++;
                }

                if ($transaction['amount'] >= 0) {
                    $receipts += $transaction['amount'];
                }
                else {
                    $payments += -$transaction['amount'];
                }

                if ($match === null) {
                    $unmatched++;
                }

                $rows[] = [
                    'idx' => $index++,
                    'tx' => $transaction,
                    'match' => $match,
                    'duplicate' => $isDuplicate,
                ];
            }

            $receipts = round($receipts, 2);
            $payments = round($payments, 2);
            $net = round($receipts - $payments, 2);
            $opening = $statement['opening_balance'];
            $closing = $statement['closing_balance'];
            $expected = $opening !== null ? round($opening + $net, 2) : null;
            $difference = ($expected !== null && $closing !== null) ? round($closing - $expected, 2) : null;
            $importable = $duplicate === null || $duplicate['level'] === 'overlap';

            $hasOverlap = $hasOverlap || ($duplicate['level'] ?? null) === 'overlap';

            if ($importable) {
                $totals['importable']++;
                $totals['transactions'] += count($rows);
                $totals['receipts'] += $receipts;
                $totals['payments'] += $payments;
                $totals['unmatched'] += $unmatched;
                $totals['duplicate_rows'] += $duplicates;
            }

            $statements[$position] = [
                'header' => array_diff_key($statement, ['transactions' => true]),
                'rows' => $rows,
                'summary' => [
                    'count' => count($rows),
                    'receipts' => $receipts,
                    'payments' => $payments,
                    'net' => $net,
                    'opening' => $opening,
                    'closing' => $closing,
                    'expected_closing' => $expected,
                    'difference' => $difference,
                    'balanced' => $difference === null ? null : abs($difference) < 0.01,
                    'unmatched' => $unmatched,
                    'duplicates' => $duplicates,
                ],
                'duplicate' => $duplicate,
                'importable' => $importable,
                'balances' => $this->balanceNotes($statement, $entityId, $cashId),
            ];
        }

        return [
            'statements' => $statements,
            'transactions' => $totals['transactions'],
            'receipts' => round($totals['receipts'], 2),
            'payments' => round($totals['payments'], 2),
            'unmatched' => $totals['unmatched'],
            'duplicate_rows' => $totals['duplicate_rows'],
            'has_overlap' => $hasOverlap,
            'importable' => $totals['importable'],
            'defaults' => $this->defaultAccountIds() + ['cash' => $cashId],
        ];
    }

    /**
     * What happens with the balances of a statement: the closing balance is always recorded (and added to balances that already
     * exist at the same date); the opening one only when the entity has no balance of the cash account at or before that date.
     * AFV (S10): except when an earlier statement of the same bank account already has a balance at that date ('covered').
     */
    protected function balanceNotes(array $statement, ?int $entityId, ?int $cashId): array
    {
        $closingDate = $statement['closing_date'] ?? $statement['date_to'];
        $openingDate = $statement['date_from'] ? Carbon::parse($statement['date_from'])->subDay()->toDateString() : null;
        $notes = ['closing' => null, 'opening' => null];

        if ($statement['closing_balance'] !== null && $closingDate) {
            $same = $this->cashBalancesAt($entityId, $cashId, $closingDate);
            $notes['closing'] = [
                'date' => $closingDate, 'amount' => $statement['closing_balance'], 'existing_count' => $same['count'], 'existing_sum' => $same['sum'],
                // AFV (S10): an earlier statement of the same bank account already has a balance at that date -> not added again
                'covered' => $entityId ? $this->balanceOfSameAccountExists($statement['account_iban'] ?? null, $entityId, $closingDate) : false,
            ];
        }

        if ($statement['opening_balance'] !== null && $openingDate) {
            $notes['opening'] = [
                'date' => $openingDate,
                'amount' => $statement['opening_balance'],
                'recorded' => !$this->hasCashBalance($entityId, $cashId, $openingDate),
            ];
        }

        return $notes;
    }

    /** @return array{count: int, sum: float} balances of the cash account of the entity exactly at the date */
    protected function cashBalancesAt(?int $entityId, ?int $cashId, string $date): array
    {
        if (!$entityId || !$cashId) {
            return ['count' => 0, 'sum' => 0.0];
        }

        $row = DB::table('fin_entries')
            ->where('company_id', $this->companyId)->where('entity_id', $entityId)
            ->where('account_id', $cashId)->where('kind', 'balance')->where('entry_date', $date)
            ->selectRaw('COUNT(*) as c, COALESCE(SUM(amount_base), 0) as s')->first();

        return ['count' => (int)$row->c, 'sum' => round((float)$row->s, 2)];
    }

    /**
     * AFV (S10): a statement of the same bank account (same IBAN, or both without one) of an import that is not undone
     * already recorded a balance at the date. Its balance rows are found by the batch and the IBAN in their description, so
     * the balances of other accounts in the same file / at the same date (which must add up) do not count.
     */
    protected function balanceOfSameAccountExists(?string $iban, int $entityId, string $date): bool
    {
        $statements = FinBankStatement::withoutGlobalScopes()->where('company_id', $this->companyId)->where('entity_id', $entityId);
        $iban = $iban !== null && $iban !== '' ? mb_substr($iban, 0, 40) : null;

        if ($iban === null) {
            $statements->whereNull('account_iban');
        }
        else {
            $statements->where('account_iban', $iban);
        }

        $batchIds = $statements->pluck('import_batch_id')->filter()->unique()->all();

        if (!$batchIds) {
            return false;
        }

        $active = FinImportBatch::withoutGlobalScopes()->where('company_id', $this->companyId)
            ->whereIn('id', $batchIds)->where('status', '!=', FinEntryImporter::STATUS_UNDONE)->pluck('id')->all();

        if (!$active) {
            return false;
        }

        $query = DB::table('fin_entries')
            ->where('company_id', $this->companyId)->where('entity_id', $entityId)
            ->where('kind', 'balance')->where('source', self::SOURCE)->where('entry_date', $date)
            ->whereIn('import_batch_id', $active);

        if ($iban !== null) {
            $query->where('description', 'like', '%' . addcslashes($iban, '%_\\') . '%');
        }

        return $query->exists();
    }

    /** The entity already has a balance of the cash account dated on or before the date. */
    protected function hasCashBalance(?int $entityId, ?int $cashId, string $date): bool
    {
        if (!$entityId || !$cashId) {
            return false;
        }

        return DB::table('fin_entries')
            ->where('company_id', $this->companyId)->where('entity_id', $entityId)
            ->where('account_id', $cashId)->where('kind', 'balance')->where('entry_date', '<=', $date)
            ->exists();
    }

    // ------------------------------------------------------------------ duplicates

    /**
     * @return array{level: string, batch_id: int|null, statement_id: int|null, date: string|null, file_name: string|null}|null
     *         level: 'file' (same file) | 'statement' (same statement) -> blocked; 'overlap' (same account, overlapping period) -> needs confirmation
     */
    public function findDuplicate(array $statement, ?string $fileHash): ?array
    {
        if ($fileHash) {
            $batch = FinImportBatch::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->whereIn('source', BankStatementParser::FORMATS)
                ->where('status', '!=', FinEntryImporter::STATUS_UNDONE)
                ->where('mapping->file_sha256', 'sha256:' . $fileHash)
                ->orderByDesc('id')->first();

            if ($batch) {
                return $this->duplicateInfo('file', $batch->id, null, $batch->created_at, $batch->file_name);
            }
        }

        $query = FinBankStatement::withoutGlobalScopes()->where('company_id', $this->companyId);

        if (($statement['account_iban'] ?? null) === null) {
            $query->whereNull('account_iban');
        }
        else {
            $query->where('account_iban', $statement['account_iban']);
        }

        $candidates = $query->get();

        if ($candidates->isEmpty()) {
            return null;
        }

        $undone = FinImportBatch::withoutGlobalScopes()->where('company_id', $this->companyId)
            ->whereIn('id', $candidates->pluck('import_batch_id')->filter()->all())
            ->where('status', FinEntryImporter::STATUS_UNDONE)->pluck('id')->all();

        $overlap = null;

        foreach ($candidates as $candidate) {
            if (in_array($candidate->import_batch_id, $undone, true)) {
                continue;
            }

            $from = $candidate->date_from?->toDateString();
            $to = $candidate->date_to?->toDateString();
            $samePeriod = $from === ($statement['date_from'] ?? null) && $to === ($statement['date_to'] ?? null);
            $sameNumber = $candidate->statement_no !== null && $candidate->statement_no === ($statement['statement_no'] ?? null);
            $sameBalances = $candidate->opening_balance !== null && $candidate->closing_balance !== null
                && $statement['opening_balance'] !== null && $statement['closing_balance'] !== null
                && abs($candidate->opening_balance - $statement['opening_balance']) < 0.005
                && abs($candidate->closing_balance - $statement['closing_balance']) < 0.005;

            if ($samePeriod && ($sameNumber || $sameBalances || ($candidate->statement_no === null && ($statement['statement_no'] ?? null) === null))) {
                return $this->duplicateInfo('statement', $candidate->import_batch_id, $candidate->id, $candidate->created_at, $candidate->file_name);
            }

            if ($from && $to && ($statement['date_from'] ?? null) && ($statement['date_to'] ?? null)
                && $from <= $statement['date_to'] && $to >= $statement['date_from']) {
                $overlap ??= $this->duplicateInfo('overlap', $candidate->import_batch_id, $candidate->id, $candidate->created_at, $candidate->file_name);
            }
        }

        return $overlap;
    }

    protected function duplicateInfo(string $level, ?int $batchId, ?int $statementId, $createdAt, ?string $fileName): array
    {
        return [
            'level' => $level,
            'batch_id' => $batchId,
            'statement_id' => $statementId,
            'date' => $createdAt ? Carbon::parse($createdAt)->toDateString() : null,
            'file_name' => $fileName,
        ];
    }

    /**
     * Transactions of earlier imports of the same bank account (same IBAN; an internal transfer between two accounts of the
     * entity has the same date, amount and reference on both statements and is not a duplicate).
     *
     * @return array<string, int> key => how many bank-statement entries of the entity already have it
     */
    protected function existingTransactionKeys(array $statement, int $entityId): array
    {
        $dates = array_filter(array_column($statement['transactions'], 'date'));

        if (!$dates) {
            return [];
        }

        $batchIds = FinBankStatement::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->when(($statement['account_iban'] ?? null) === null, fn($q) => $q->whereNull('account_iban'), fn($q) => $q->where('account_iban', $statement['account_iban']))
            ->whereNotNull('import_batch_id')
            ->pluck('import_batch_id')->unique()->all();

        if (!$batchIds) {
            return [];
        }

        $keys = [];

        DB::table('fin_entries')
            ->where('company_id', $this->companyId)->where('entity_id', $entityId)
            ->where('source', self::SOURCE)->where('kind', 'flow')
            ->whereIn('import_batch_id', $batchIds)
            ->whereBetween('entry_date', [min($dates), max($dates)])
            ->select(['entry_date', 'amount', 'currency', 'document_no'])
            ->orderBy('id')
            ->each(function ($row) use (&$keys) {
                $key = $this->key(substr((string)$row->entry_date, 0, 10), (float)$row->amount, (string)$row->currency, $row->document_no);
                $keys[$key] = ($keys[$key] ?? 0) + 1;
            });

        return $keys;
    }

    protected function transactionKey(array $transaction): string
    {
        return $this->key($transaction['date'], abs($transaction['amount']), (string)($transaction['currency'] ?? ''), $this->documentNo($transaction));
    }

    protected function key(string $date, float $amount, string $currency, ?string $documentNo): string
    {
        return $date . '|' . number_format($amount, 2, '.', '') . '|' . strtoupper($currency) . '|' . mb_strtolower((string)$documentNo);
    }

    protected function documentNo(array $transaction): ?string
    {
        $value = $transaction['reference'] ?? null ?: ($transaction['bank_reference'] ?? null);

        return $value !== null && $value !== '' ? mb_substr((string)$value, 0, 60) : null;
    }

    // ------------------------------------------------------------------ import

    /**
     * Import the statements of a parsed file.
     *
     * $options:
     *  - file_name, user_id
     *  - cash_account_id: account of the balances (default 503)
     *  - record_balances (default true): record the closing (and opening) balances
     *  - allow_overlap (default false): confirm statements whose period overlaps an earlier import of the same account
     *  - decisions (the review choices; row numbers are the 'idx' of review()):
     *      default_credit / default_debit: account ids for transactions without a rule (default 709 / 609; null or 0 = skip them)
     *      accounts: [idx => account id | 0 to skip the row], include: [idx => bool], remember: [idx => bool]
     *
     * @throws BankStatementException
     */
    public function import(array $parsed, int $entityId, array $options = []): FinImportBatch
    {
        $entity = FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->find($entityId);

        if (!$entity) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.entity_required'));
        }

        $review = $this->review($parsed, $entityId);

        if ($review['has_overlap'] && empty($options['allow_overlap'])) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.overlap_confirm'));
        }

        if ($review['importable'] === 0) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.all_duplicates'));
        }

        $recordBalances = $options['record_balances'] ?? true;
        $cash = $this->accounts()[(int)($options['cash_account_id'] ?? $this->defaultCashAccountId())] ?? null;

        if ($recordBalances && (!$cash || $cash['subtype'] !== 'cash')) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.no_cash_account'));
        }

        // without a choice the suggested default accounts are used; an explicit null / 0 means "skip transactions without a rule"
        $decisions = $options['decisions'] ?? [];
        $suggested = $this->defaultAccountIds();
        $defaults = [
            'credit' => array_key_exists('default_credit', $decisions) ? $this->validAccount($decisions['default_credit'], 'credit') : $suggested['credit'],
            'debit' => array_key_exists('default_debit', $decisions) ? $this->validAccount($decisions['default_debit'], 'debit') : $suggested['debit'],
        ];
        $userId = $options['user_id'] ?? $this->currentUserId();

        return DB::transaction(function () use ($parsed, $review, $entity, $options, $decisions, $defaults, $cash, $recordBalances, $userId) {
            $batch = new FinImportBatch();
            $batch->company_id = $this->companyId;
            $batch->source = $parsed['format'];
            $batch->file_name = $options['file_name'] ?? null;
            $batch->mapping = array_filter([
                'format' => $parsed['format'],
                'file_sha256' => !empty($parsed['file_hash']) ? 'sha256:' . $parsed['file_hash'] : null,
                'entity' => $entity->name,
                'cash_account' => $recordBalances && $cash ? $cash['code'] . ' ' . $cash['name'] : null,
                'default_credit' => $defaults['credit'] ? $this->accountLabel($defaults['credit']) : null,
                'default_debit' => $defaults['debit'] ? $this->accountLabel($defaults['debit']) : null,
                'balances' => $recordBalances ? 'yes' : 'no',
            ]);
            $batch->rows_total = $review['transactions'];
            $batch->status = FinEntryImporter::STATUS_PROCESSING;
            $batch->created_by = $userId;
            $batch->save();

            $matcher = new CounterpartyMatcher($this->companyId, $entity->id);

            // 1. "remember" choices become rules first, so the other rows of the same counterparty use them too
            $rules = $this->rememberRules($review, $decisions, $defaults, $matcher, $entity->id, $userId);

            if ($rules > 0) {
                $matcher->reload();
            }

            // 2. entries and statement headers
            $imported = $failed = 0;
            $errors = [];
            $pending = [];
            $now = now();

            foreach ($review['statements'] as $statementReview) {
                if (!$statementReview['importable']) {
                    continue;
                }

                $header = $statementReview['header'];
                $count = 0;

                foreach ($statementReview['rows'] as $row) {
                    $idx = $row['idx'];
                    $include = array_key_exists($idx, $decisions['include'] ?? []) ? (bool)$decisions['include'][$idx] : !$row['duplicate'];

                    if (!$include) {
                        continue;
                    }

                    $transaction = $row['tx'];
                    $direction = $transaction['direction'];
                    $match = null;

                    if (array_key_exists($idx, $decisions['accounts'] ?? [])) {
                        $accountId = (int)$decisions['accounts'][$idx] ?: null;
                    }
                    else {
                        $match = $matcher->match($transaction);
                        $accountId = $match['account_id'] ?? $defaults[$direction];
                    }

                    if ($accountId === null) {
                        continue; // no account for this transaction: skipped
                    }

                    if ($this->validAccount($accountId, $direction) === null) {
                        $failed++;
                        $this->addError($errors, $idx, __('databackd-gaps-bank-import.errors.account_direction'));
                        continue;
                    }

                    $amount = round(abs($transaction['amount']), 2);

                    if ($amount <= 0 || $amount > 9999999999999.99) {
                        $failed++;
                        $this->addError($errors, $idx, __('databackd-gaps-bank-import.errors.amount_invalid'));
                        continue;
                    }

                    [$amountBase, $warning] = $this->toBase($amount, $transaction['currency'] ?? $header['currency'], $transaction['date']);

                    if ($warning !== null) {
                        $this->addError($errors, $idx, $warning);
                    }

                    $description = $transaction['description'] ?? $transaction['type_code'] ?? null;

                    $pending[] = [
                        'company_id' => $this->companyId,
                        'entity_id' => $entity->id,
                        'account_id' => $accountId,
                        'entry_date' => $transaction['date'],
                        'kind' => 'flow',
                        'amount' => $amount,
                        'currency' => strtoupper($transaction['currency'] ?? $header['currency']),
                        'amount_base' => $amountBase,
                        'region_id' => null,
                        'client_segment_id' => null,
                        'product_group_id' => null,
                        'cost_center_id' => null,
                        'client_id' => null,
                        // AFV (S10): varchar(191) columns
                        'counterparty_name' => $this->limit($match['counterparty_name'] ?? null ?: ($transaction['counterparty_name'] ?? null), 191),
                        'document_no' => $this->documentNo($transaction),
                        'description' => $this->limit($description, 191),
                        'source' => self::SOURCE,
                        'import_batch_id' => $batch->id,
                        'created_by' => $userId,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                    $imported++;
                    $count++;

                    if (count($pending) >= 500) {
                        DB::table('fin_entries')->insert($pending);
                        $pending = [];
                    }
                }

                if ($pending) {
                    DB::table('fin_entries')->insert($pending);
                    $pending = [];
                }

                $statement = new FinBankStatement();
                $statement->company_id = $this->companyId;
                $statement->import_batch_id = $batch->id;
                $statement->entity_id = $entity->id;
                $statement->format = $parsed['format'];
                $statement->account_iban = $header['account_iban'] !== null ? mb_substr($header['account_iban'], 0, 40) : null;
                $statement->currency = strtoupper($header['currency']);
                $statement->statement_no = $header['statement_no'] !== null ? mb_substr($header['statement_no'], 0, 60) : null;
                $statement->date_from = $header['date_from'];
                $statement->date_to = $header['date_to'];
                $statement->opening_balance = $header['opening_balance'];
                $statement->closing_balance = $header['closing_balance'];
                $statement->transactions_count = $count;
                $statement->file_name = $batch->file_name ? mb_substr($batch->file_name, 0, 191) : null;
                $statement->created_by = $userId;
                $statement->save();

                if ($recordBalances && $cash) {
                    $this->recordBalances($header, $batch->id, $entity->id, $cash, $userId, $errors);
                }
            }

            $batch->rows_imported = $imported;
            $batch->rows_failed = $failed;
            $batch->errors = $errors ?: null;
            $batch->status = ($failed > 0) ? ($imported > 0 ? FinEntryImporter::STATUS_PARTIAL : FinEntryImporter::STATUS_FAILED) : FinEntryImporter::STATUS_COMPLETED;
            $batch->save();

            return $batch;
        });
    }

    /**
     * Opening and closing balance of a statement as balances of the cash account (see the class description).
     */
    protected function recordBalances(array $header, int $batchId, int $entityId, array $cash, ?int $userId, array &$errors): void
    {
        $closingDate = $header['closing_date'] ?? $header['date_to'];
        $openingDate = $header['date_from'] ? Carbon::parse($header['date_from'])->subDay()->toDateString() : null;
        $currency = strtoupper($header['currency']);
        $label = ($header['statement_no'] ?? null) ? ($header['statement_no'] . ' ') : '';
        $iban = $header['account_iban'] ?? '';

        if ($header['opening_balance'] !== null && $openingDate && !$this->hasCashBalance($entityId, $cash['id'], $openingDate)) {
            $this->insertBalance($header['opening_balance'], $openingDate, $currency, $cash, $batchId, $entityId, $userId, $errors,
                __('databackd-gaps-bank-import.balance.opening', ['iban' => $iban, 'no' => trim($label)]));
        }

        if ($header['closing_balance'] !== null && $closingDate) {
            // AFV (S10): a confirmed overlapping statement of the same account must not add its balance a second time
            // (balances at one date add up). Skipped, not replaced: undo of either import deletes only its own rows.
            if ($this->balanceOfSameAccountExists($header['account_iban'] ?? null, $entityId, $closingDate)) {
                $this->addError($errors, null, __('databackd-gaps-bank-import.warnings.balance_exists', [
                    'amount' => number_format($header['closing_balance'], 2, ',', ' '), 'date' => $closingDate,
                ]));

                return;
            }

            $this->insertBalance($header['closing_balance'], $closingDate, $currency, $cash, $batchId, $entityId, $userId, $errors,
                __('databackd-gaps-bank-import.balance.closing', ['iban' => $iban, 'no' => trim($label)]));
        }
    }

    /** A negative balance (overdraft) cannot be stored (amounts are positive): the cash balance is 0 and the debt goes to account 498. */
    protected function insertBalance(float $balance, string $date, string $currency, array $cash, int $batchId, int $entityId, ?int $userId, array &$errors, string $description): void
    {
        $rows = [[$cash['id'], max($balance, 0.0), $description]];

        if ($balance < 0) {
            $overdraft = $this->accountByCode(self::OVERDRAFT_CODE);

            if ($overdraft && $overdraft['type'] === 'liability') {
                $rows[] = [$overdraft['id'], abs($balance), $description];
            }
            else {
                $this->addError($errors, null, __('databackd-gaps-bank-import.warnings.overdraft', ['amount' => number_format($balance, 2, ',', ' '), 'date' => $date]));
            }
        }

        $now = now();

        foreach ($rows as [$accountId, $amount, $text]) {
            [$base, $warning] = $this->toBase(round($amount, 2), $currency, $date);

            if ($warning !== null) {
                $this->addError($errors, null, $warning);
            }

            DB::table('fin_entries')->insert([
                'company_id' => $this->companyId,
                'entity_id' => $entityId,
                'account_id' => $accountId,
                'entry_date' => $date,
                'kind' => 'balance',
                'amount' => round($amount, 2),
                'currency' => $currency,
                'amount_base' => $base,
                'counterparty_name' => null,
                'document_no' => null,
                'description' => $this->limit($text, 191), // AFV (S10): varchar(191)
                'source' => self::SOURCE,
                'import_batch_id' => $batchId,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }
    }

    /**
     * Create (or update) the rules of the rows ticked "remember the counterparty". Rule: contains <name or IBAN>, direction of the row,
     * all legal entities. Returns how many rules were created or changed.
     */
    protected function rememberRules(array $review, array $decisions, array $defaults, CounterpartyMatcher $matcher, int $entityId, ?int $userId): int
    {
        $remember = array_filter((array)($decisions['remember'] ?? []));

        if (!$remember) {
            return 0;
        }

        $changed = 0;
        $done = [];

        foreach ($review['statements'] as $statementReview) {
            if (!$statementReview['importable']) {
                continue;
            }

            foreach ($statementReview['rows'] as $row) {
                $idx = $row['idx'];

                if (empty($remember[$idx])) {
                    continue;
                }

                $transaction = $row['tx'];
                $direction = $transaction['direction'];
                $pattern = trim((string)($transaction['counterparty_name'] ?? '')) ?: (string)($transaction['counterparty_iban'] ?? '');

                if ($pattern === '') {
                    continue;
                }

                if (array_key_exists($idx, $decisions['accounts'] ?? [])) {
                    $accountId = (int)$decisions['accounts'][$idx] ?: null;
                }
                else {
                    $accountId = ($row['match']['account_id'] ?? null) ?: $defaults[$direction];
                }

                if ($accountId === null || $this->validAccount($accountId, $direction) === null) {
                    continue;
                }

                // a rule already books this counterparty to the chosen account: nothing to remember
                if (!array_key_exists($idx, $decisions['accounts'] ?? []) && !empty($row['match']['account_id']) && (int)$row['match']['account_id'] === (int)$accountId) {
                    continue;
                }

                $key = $direction . '|' . mb_strtolower($pattern);

                if (isset($done[$key])) {
                    continue;
                }

                $done[$key] = true;
                $changed += $this->saveRule($pattern, $direction, $accountId, $transaction['counterparty_name'] ?? null, $userId) ? 1 : 0;
            }
        }

        return $changed;
    }

    /** Create a rule "contains <pattern>" for all entities, or point the identical existing rule to the account. */
    public function saveRule(string $pattern, string $direction, int $accountId, ?string $counterpartyName, ?int $userId = null, string $matchType = 'contains'): bool
    {
        $pattern = mb_substr(trim($pattern), 0, 191); // AFV (S10): varchar(191)

        $rule = FinCounterpartyMapping::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereRaw('LOWER(pattern) = ?', [mb_strtolower($pattern)])
            ->where('match_type', $matchType)
            ->where('direction', $direction)
            ->whereNull('entity_id')
            ->first();

        if ($rule && (int)$rule->account_id === $accountId) {
            return false;
        }

        if (!$rule) {
            $rule = new FinCounterpartyMapping();
            $rule->company_id = $this->companyId;
            $rule->pattern = $pattern;
            $rule->match_type = $matchType;
            $rule->direction = $direction;
            $rule->entity_id = null;
            $rule->created_by = $userId;
        }

        $rule->account_id = $accountId;
        $rule->counterparty_name = $counterpartyName !== null && trim($counterpartyName) !== '' ? mb_substr(trim($counterpartyName), 0, 191) : $rule->counterparty_name;
        $rule->save();

        return true;
    }

    // ------------------------------------------------------------------ undo

    /**
     * Delete everything an import created (entries, balances, statement headers) and mark the batch as undone.
     * Safe to call again for a batch that was undone from the import history page (that page leaves the statement headers behind).
     *
     * @return int number of deleted entries
     */
    public function undo(FinImportBatch $batch): int
    {
        if ((int)$batch->company_id !== $this->companyId) {
            throw new \InvalidArgumentException('The batch belongs to another company.');
        }

        return DB::transaction(function () use ($batch) {
            $deleted = FinEntry::withoutGlobalScopes()
                ->where('company_id', $this->companyId)->where('import_batch_id', $batch->id)->delete();

            FinBankStatement::withoutGlobalScopes()
                ->where('company_id', $this->companyId)->where('import_batch_id', $batch->id)->delete();

            $batch->status = FinEntryImporter::STATUS_UNDONE;
            $batch->save();

            return $deleted;
        });
    }

    // ------------------------------------------------------------------ helpers

    /** The account id when it is an active revenue (credit) / expense (debit) account of the company, otherwise null. */
    protected function validAccount(mixed $accountId, string $direction): ?int
    {
        $accountId = (int)$accountId;
        $account = $accountId ? ($this->accounts()[$accountId] ?? null) : null;

        if (!$account || !$account['is_active'] || $account['type'] !== CounterpartyMatcher::ACCOUNT_TYPE_BY_DIRECTION[$direction]) {
            return null;
        }

        return $accountId;
    }

    protected function accountLabel(int $accountId): string
    {
        $account = $this->accounts()[$accountId] ?? null;

        return $account ? $account['code'] . ' ' . $account['name'] : (string)$accountId;
    }

    protected function addError(array &$errors, ?int $index, string $message): void
    {
        if (count($errors) < self::MAX_ERRORS) {
            $errors[] = ['row' => $index !== null ? $index + 1 : 0, 'messages' => [$message]];
        }
    }

    protected function limit(?string $value, int $length): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : mb_substr($value, 0, $length);
    }

    protected function currentUserId(): ?int
    {
        try {
            return auth()->check() ? user()?->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function company(): Company
    {
        return $this->company ??= Company::withoutGlobalScopes()->with('currency')->findOrFail($this->companyId);
    }

    protected function baseCurrency(): string
    {
        return $this->baseCurrency ??= ($this->company()->currency->currency_code ?? 'EUR');
    }

    /**
     * Amount in the company currency: the same currency as is; otherwise the exchange rate service (B6) when it can give a rate,
     * then the fixed BGN/EUR rate, then the rate of the AFV currency; without any rate the amount is kept and a warning returned.
     *
     * @return array{0: float, 1: string|null} [amount_base, warning]
     */
    protected function toBase(float $amount, string $currency, string $date): array
    {
        $base = $this->baseCurrency();
        $currency = strtoupper($currency);

        if ($currency === $base) {
            return [round($amount, 2), null];
        }

        if (class_exists(FxRateService::class)) {
            try {
                $this->fx ??= new FxRateService($this->companyId);
                $converted = $this->fx->convert($amount, $currency, $base, $date);

                if ($converted !== null) {
                    return [round($converted, 2), null];
                }
            } catch (\Throwable $e) {
                // fall through to the simple rates
            }
        }

        if ($currency === 'BGN' && $base === 'EUR') {
            return [round($amount / FinEntryImporter::BGN_PER_EUR, 2), null];
        }

        if ($currency === 'EUR' && $base === 'BGN') {
            return [round($amount * FinEntryImporter::BGN_PER_EUR, 2), null];
        }

        $rate = (float)Currency::withoutGlobalScopes()->where('company_id', $this->companyId)->where('currency_code', $currency)->value('exchange_rate');

        // AFV (S10): AFV exchange_rate = units of the base currency for 1 unit of this currency
        if ($rate > 0) {
            return [round($amount * $rate, 2), null];
        }

        return [round($amount, 2), __('databackd-gaps-bank-import.warnings.no_rate', ['currency' => $currency, 'base' => $base, 'date' => $date])];
    }

}

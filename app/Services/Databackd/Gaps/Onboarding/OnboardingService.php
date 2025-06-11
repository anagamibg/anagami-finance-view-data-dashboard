<?php

namespace App\Services\Databackd\Gaps\Onboarding;

use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBudgetLine;
use App\Models\Databackd\FinCounterpartyMapping;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinImportBatch;
use App\Models\Databackd\FinOnboarding;
use App\Scopes\CompanyScope;
use App\Services\Databackd\ChartOfAccounts;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;
use Throwable;

/**
 * First-time setup wizard of a company (R9, B5): state of the five steps in fin_onboarding
 * (company -> entities -> chart -> import -> dashboard), progress, chart-of-accounts templates.
 *
 * steps = {"company": "done|skipped|pending", "entities": ..., "chart": ..., "import": ..., "dashboard": ...}
 * (a missing key is read as "pending"). A company with a completed wizard has completed_at set; "dismissed_at" hides the
 * menu item and the dashboard banner without completing the wizard.
 *
 * Works without a logged-in user (commands, seeders): everything takes the company id and bypasses CompanyScope.
 */
class OnboardingService
{

    const STATUSES = ['done', 'skipped', 'pending'];

    const TEMPLATES = ['nss', 'micro'];

    /**
     * Simplified chart for micro companies: the accounts that are active with the "micro" template (16 of the 29
     * standard NSS accounts). All other standard accounts become inactive - unless they already have records.
     *   capital:     101 Share capital, 121 Retained earnings
     *   cash:        501 Cash on hand, 503 Bank account
     *   receivables: 411 Customers
     *   payables:    401 Suppliers, 421 Personnel, 453 VAT settlements
     *   revenue:     702 Goods, 703 Services, 709 Other revenue
     *   expenses:    601 Materials, 602 External services, 604 Salaries, 605 Social security, 609 Other expenses
     */
    const MICRO_CODES = [
        '101', '121',
        '501', '503',
        '411',
        '401', '421', '453',
        '702', '703', '709',
        '601', '602', '604', '605', '609',
    ];

    /** @var array<int, array|null> per-request memo of visibleState() */
    protected static array $stateMemo = [];

    // ------------------------------------------------------------------ state

    /**
     * The wizard row of a company; created on first use (steps all pending).
     */
    public function forCompany(int $companyId): FinOnboarding
    {
        $onboarding = FinOnboarding::withoutGlobalScope(CompanyScope::class)->firstOrCreate(
            ['company_id' => $companyId],
            ['steps' => $this->defaultSteps(), 'created_by' => $this->currentUserId()]
        );

        unset(self::$stateMemo[$companyId]);

        return $onboarding;
    }

    /**
     * The normalised statuses of the five steps: [step => done|skipped|pending] in wizard order.
     *
     * @return array<string, string>
     */
    public function steps(FinOnboarding $onboarding): array
    {
        $stored = is_array($onboarding->steps) ? $onboarding->steps : [];
        $steps = [];

        foreach (FinOnboarding::STEPS as $key) {
            $status = $stored[$key] ?? 'pending';
            $steps[$key] = in_array($status, self::STATUSES, true) ? $status : 'pending';
        }

        return $steps;
    }

    /**
     * @return array{done:int, total:int, percent:int, current:string, skipped:int, completed:bool}
     *   done = steps that are handled (done or skipped); current = key of the first pending step (the last step when
     *   nothing is pending). A completed wizard (completed_at) is always 100 %.
     */
    public function progress(FinOnboarding $onboarding): array
    {
        $steps = $this->steps($onboarding);
        $total = count(FinOnboarding::STEPS);
        $completed = $onboarding->completed_at !== null;

        if ($completed) {
            return [
                'done' => $total,
                'total' => $total,
                'percent' => 100,
                'current' => $this->lastStep(),
                'skipped' => count(array_filter($steps, fn($status) => $status === 'skipped')),
                'completed' => true,
            ];
        }

        $handled = count(array_filter($steps, fn($status) => $status !== 'pending'));
        $current = array_search('pending', $steps, true);

        return [
            'done' => $handled,
            'total' => $total,
            'percent' => (int)round($handled / $total * 100),
            'current' => $current === false ? $this->lastStep() : $current,
            'skipped' => count(array_filter($steps, fn($status) => $status === 'skipped')),
            'completed' => false,
        ];
    }

    /**
     * Set the status of one step (done|skipped|pending).
     */
    public function markStep(FinOnboarding $onboarding, string $step, string $status): FinOnboarding
    {
        if (!in_array($step, FinOnboarding::STEPS, true)) {
            throw new InvalidArgumentException('Unknown onboarding step: ' . $step);
        }

        if (!in_array($status, self::STATUSES, true)) {
            throw new InvalidArgumentException('Unknown onboarding step status: ' . $status);
        }

        $steps = $this->steps($onboarding);
        $steps[$step] = $status;

        $onboarding->steps = $steps;
        $onboarding->save();

        unset(self::$stateMemo[$onboarding->company_id]);

        return $onboarding;
    }

    /**
     * Finish the wizard: steps that are still pending become "skipped", the last step is "done", completed_at = now.
     */
    public function complete(FinOnboarding $onboarding): FinOnboarding
    {
        $steps = $this->steps($onboarding);

        foreach ($steps as $key => $status) {
            if ($status === 'pending') {
                $steps[$key] = 'skipped';
            }
        }

        $steps['dashboard'] = 'done';

        $onboarding->steps = $steps;
        $onboarding->completed_at = now();
        $onboarding->dismissed_at = null;
        $onboarding->save();

        unset(self::$stateMemo[$onboarding->company_id]);

        return $onboarding;
    }

    /**
     * Hide the wizard (menu item and dashboard banner) without completing it.
     */
    public function dismiss(FinOnboarding $onboarding): FinOnboarding
    {
        $onboarding->dismissed_at = now();
        $onboarding->save();

        unset(self::$stateMemo[$onboarding->company_id]);

        return $onboarding;
    }

    /**
     * Show a dismissed wizard again.
     */
    public function restore(FinOnboarding $onboarding): FinOnboarding
    {
        $onboarding->dismissed_at = null;
        $onboarding->save();

        unset(self::$stateMemo[$onboarding->company_id]);

        return $onboarding;
    }

    /**
     * Whether the menu item / dashboard banner should be shown: not completed and not dismissed.
     * A company without a row has not started yet, so it is visible.
     */
    public function isVisible(?FinOnboarding $onboarding): bool
    {
        return $onboarding === null || ($onboarding->completed_at === null && $onboarding->dismissed_at === null);
    }

    /**
     * Read-only helper for the menu item and the dashboard banner (never creates the row, never throws).
     *
     * @return array{onboarding: ?FinOnboarding, progress: array}|null null when the wizard is completed / dismissed
     */
    public function visibleState(int $companyId): ?array
    {
        if (array_key_exists($companyId, self::$stateMemo)) {
            return self::$stateMemo[$companyId];
        }

        try {
            $onboarding = FinOnboarding::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->first();

            if (!$this->isVisible($onboarding)) {
                return self::$stateMemo[$companyId] = null;
            }

            $progress = $onboarding
                ? $this->progress($onboarding)
                : ['done' => 0, 'total' => count(FinOnboarding::STEPS), 'percent' => 0, 'current' => FinOnboarding::STEPS[0], 'skipped' => 0, 'completed' => false];

            return self::$stateMemo[$companyId] = ['onboarding' => $onboarding, 'progress' => $progress];
        } catch (Throwable $e) {
            // the sidebar and the dashboard must never break because of the wizard
            return self::$stateMemo[$companyId] = null;
        }
    }

    /**
     * Mark the import step as done when the company has imported data meanwhile (the user came back from the import
     * wizard; a skipped step becomes done too). Other steps are never detected automatically.
     */
    public function syncDetected(FinOnboarding $onboarding): FinOnboarding
    {
        if ($this->steps($onboarding)['import'] !== 'done' && $this->importCount($onboarding->company_id) > 0) {
            $this->markStep($onboarding, 'import', 'done');
        }

        return $onboarding;
    }

    // ------------------------------------------------------------------ chart of accounts

    /**
     * Apply a chart-of-accounts template and remember it in fin_onboarding.chart_template (+ step "chart" = done).
     *   nss   - the full standard NSS chart: missing standard accounts are created, all 29 are active.
     *   micro - the simplified chart (MICRO_CODES); the other standard accounts get is_active = false.
     * Safe for existing data: nothing is deleted, an account that has records (entries, budget lines, counterparty
     * mappings) is never deactivated, accounts with non-standard codes (added by the user) are never touched.
     *
     * @return array{template: string, created: int, activated: string[], deactivated: string[], kept_active: string[],
     *               active: string[], inactive: string[]}
     *   created/activated/deactivated = what this call changed (codes); kept_active = accounts outside the template
     *   that stay active because they have records; active/inactive = the standard accounts after the call.
     */
    public function applyChartTemplate(int $companyId, string $template): array
    {
        if (!in_array($template, self::TEMPLATES, true)) {
            throw new InvalidArgumentException('Unknown chart template: ' . $template);
        }

        return DB::transaction(function () use ($companyId, $template) {
            $created = ChartOfAccounts::ensureFor($companyId);

            $standard = array_map('strval', array_keys(ChartOfAccounts::ACCOUNTS));
            $wanted = $template === 'micro' ? self::MICRO_CODES : $standard;
            $inUse = array_flip($this->accountIdsInUse($companyId));

            $accounts = FinAccount::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereIn('code', $standard)
                ->orderBy('code')
                ->get();

            $activated = $deactivated = $keptActive = $active = $inactive = [];

            foreach ($accounts as $account) {
                if (in_array($account->code, $wanted, true)) {
                    if (!$account->is_active) {
                        $account->is_active = true;
                        $account->save();
                        $activated[] = $account->code;
                    }
                } elseif (isset($inUse[$account->id])) {
                    if ($account->is_active) {
                        $keptActive[] = $account->code;
                    }
                } elseif ($account->is_active) {
                    $account->is_active = false;
                    $account->save();
                    $deactivated[] = $account->code;
                }

                if ($account->is_active) {
                    $active[] = $account->code;
                } else {
                    $inactive[] = $account->code;
                }
            }

            $onboarding = $this->forCompany($companyId);
            $steps = $this->steps($onboarding);
            $steps['chart'] = 'done';
            $onboarding->steps = $steps;
            $onboarding->chart_template = $template;
            $onboarding->save();

            unset(self::$stateMemo[$companyId]);

            return [
                'template' => $template,
                'created' => $created,
                'activated' => $activated,
                'deactivated' => $deactivated,
                'kept_active' => $keptActive,
                'active' => $active,
                'inactive' => $inactive,
            ];
        });
    }

    /**
     * Ids of the accounts of a company that have records and therefore must stay active.
     *
     * @return int[]
     */
    public function accountIdsInUse(int $companyId): array
    {
        $ids = [];

        foreach ([FinEntry::class, FinBudgetLine::class, FinCounterpartyMapping::class] as $model) {
            $found = $model::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $companyId)
                ->whereNotNull('account_id')
                ->distinct()
                ->pluck('account_id')
                ->all();

            $ids = array_merge($ids, $found);
        }

        return array_values(array_unique(array_map('intval', $ids)));
    }

    // ------------------------------------------------------------------ summaries (for the wizard screens)

    /**
     * Counters shown in the wizard: entities, accounts, records and imports of the company.
     *
     * @return array{entities: int, accounts_total: int, accounts_active: int, accounts_in_use: int, entries: int, imports: int}
     */
    public function summary(int $companyId): array
    {
        $accounts = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId);

        return [
            'entities' => FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->count(),
            'accounts_total' => (clone $accounts)->count(),
            'accounts_active' => (clone $accounts)->where('is_active', true)->count(),
            'accounts_in_use' => count($this->accountIdsInUse($companyId)),
            'entries' => FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $companyId)->count(),
            'imports' => $this->importCount($companyId),
        ];
    }

    /**
     * Number of finished imports of the company (batches that imported at least one row).
     */
    public function importCount(int $companyId): int
    {
        return FinImportBatch::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('rows_imported', '>', 0)
            ->count();
    }

    /**
     * code => name of the accounts that a template activates (names are the standard NSS names).
     *
     * @return array<string, string>
     */
    public function templateAccounts(string $template): array
    {
        $codes = $template === 'micro' ? self::MICRO_CODES : array_map('strval', array_keys(ChartOfAccounts::ACCOUNTS));
        $accounts = [];

        foreach ($codes as $code) {
            $accounts[$code] = ChartOfAccounts::ACCOUNTS[$code][0];
        }

        return $accounts;
    }

    // ------------------------------------------------------------------ internals

    protected function lastStep(): string
    {
        return FinOnboarding::STEPS[count(FinOnboarding::STEPS) - 1];
    }

    protected function defaultSteps(): array
    {
        return array_fill_keys(FinOnboarding::STEPS, 'pending');
    }

    protected function currentUserId(): ?int
    {
        try {
            if (auth()->hasUser() && function_exists('user') && user()) {
                return (int)user()->id;
            }
        } catch (Throwable $e) {
            // console / seeders: no user
        }

        return null;
    }

}

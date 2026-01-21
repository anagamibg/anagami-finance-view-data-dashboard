<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\Databackd\FinAlertEvent;
use App\Models\Databackd\FinAlertRule;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\RatioCalculator;
use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Planning\BudgetActuals;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;

/**
 * Demo data of the alerts and thresholds screen (B4): 6 rules (all four types, 4 active + 2 switched off, three
 * severities, app / app + e-mail channels) and their history.
 *
 * The history is NOT invented: the seeder evaluates every rule on the real demo data of every closed month from
 * March to September 2026 (a month is checked on the first day of the next month at about 07:00) and records an event for
 * every month in which the condition was violated - value, threshold and period are the real ones. The thresholds below
 * were picked so that about 26 events appear over the 7 months. The October check will run on 1 November, so the
 * newest events are dated 2026-10-01. A rule that was switched off has no events after that date and keeps the state
 * of its last check.
 *
 * Metrics (the same as the rule PHPDoc in FinAlertRule):
 *  - ratio_range: the FinRatio value of the month (RatioCalculator), for one legal entity or the whole group;
 *  - budget_deviation: |actual - budget| / budget of the month for the revenue lines of the approved budget;
 *  - cash_below: cash (501 + 503) at the end of the month;
 *  - receivables_above: receivables (411) at the end of the month.
 *
 * Idempotent: the 6 rules are found by name; they and their events are removed and rebuilt. Other rules / events stay.
 * The ids of ratios and budgets are resolved by code / name, so the seeder also works on production. Run it after
 * AnalyticsDemoSeeder (ratios), PlanningDemoSeeder (budgets) and BankStatementsDemoSeeder (the Sep / Oct turnover
 * of the parent company is part of the evaluated data). Notifications are NOT sent.
 */
class AlertsDemoSeeder extends Seeder
{

    const FIRST_MONTH = '2026-03-01';
    const LAST_MONTH = '2026-09-01';
    const LAST_CHECK = '2026-10-28 07:00:00';

    const MONTHS = ['', 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];

    const PARENT = 'Демо Финанс Груп АД';
    const CONSULT = 'Демо Консулт ООД';

    /**
     * key => rule definition. `off` = date the rule was switched off (null = active); `created` = creation date.
     */
    const RULES = [
        'liquidity' => [
            'name' => 'Текуща ликвидност под 1,6 – Демо Финанс Груп АД',
            'type' => 'ratio_range', 'entity' => self::PARENT, 'ratio' => 'current_ratio', 'min' => 1.6, 'max' => null,
            'severity' => 'warning', 'app' => true, 'email' => true, 'recipients' => ['admin@demofinans.bg', 'g.petrov@demofinans.bg'],
            'created' => '2026-03-06 10:20:00', 'off' => null,
        ],
        'ext_services' => [
            'name' => 'Външни услуги над 20 % от приходите – групата',
            'type' => 'ratio_range', 'entity' => null, 'ratio' => 'custom_ext_services_pct', 'min' => null, 'max' => 20.0,
            'severity' => 'info', 'app' => true, 'email' => false, 'recipients' => null,
            'created' => '2026-03-06 10:25:00', 'off' => '2026-10-12 08:00:00',
        ],
        'budget_revenue' => [
            'name' => 'Приходите на Демо Консулт ООД се отклоняват от бюджета с над 8 %',
            'type' => 'budget_deviation', 'entity' => self::CONSULT, 'budget' => 'Бюджет 2026 – Демо Консулт ООД', 'pct' => 8.0, 'metric' => 'revenue',
            'severity' => 'warning', 'app' => true, 'email' => true, 'recipients' => ['admin@demofinans.bg'],
            'created' => '2026-03-12 14:05:00', 'off' => null,
        ],
        'cash' => [
            'name' => 'Парични средства под 240 000 EUR – Демо Консулт ООД',
            'type' => 'cash_below', 'entity' => self::CONSULT, 'amount' => 240000.0,
            'severity' => 'critical', 'app' => true, 'email' => true, 'recipients' => ['admin@demofinans.bg', 'g.petrov@demofinans.bg'],
            'created' => '2026-03-12 14:12:00', 'off' => null,
        ],
        'receivables' => [
            'name' => 'Вземания от клиенти над 120 000 EUR – Демо Финанс Груп АД',
            'type' => 'receivables_above', 'entity' => self::PARENT, 'amount' => 120000.0,
            'severity' => 'warning', 'app' => true, 'email' => false, 'recipients' => null,
            'created' => '2026-03-20 09:40:00', 'off' => null,
        ],
        'payroll' => [
            'name' => 'Разходи за персонал над 38 % от приходите – Демо Финанс Груп АД',
            'type' => 'ratio_range', 'entity' => self::PARENT, 'ratio' => 'payroll_to_revenue', 'min' => null, 'max' => 38.0,
            'severity' => 'info', 'app' => true, 'email' => false, 'recipients' => null,
            'created' => '2026-03-20 09:45:00', 'off' => '2026-08-20 08:00:00',
        ],
    ];

    protected int $companyId = 0;
    protected ?int $adminId = null;
    protected ?int $analystId = null;

    /** @var array<string, int> entity name => id */
    protected array $entities = [];
    /** @var array<string, FinRatio> */
    protected array $ratios = [];
    /** @var array<string, FinBudget> budget name => budget */
    protected array $budgets = [];

    protected RatioCalculator $calculator;
    protected FinancialDataService $data;
    protected BudgetActuals $actuals;

    public function run(Company $company)
    {
        $this->companyId = $company->id;

        $users = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->whereIn('email', [DatabackdDemoSeeder::ADMIN_EMAIL, 'g.petrov@demofinans.bg'])
            ->pluck('id', 'email')->all();
        $this->adminId = $users[DatabackdDemoSeeder::ADMIN_EMAIL] ?? null;
        $this->analystId = $users['g.petrov@demofinans.bg'] ?? $this->adminId;

        $this->entities = FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'name')->all();
        $this->ratios = FinRatio::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get()->keyBy('code')->all();
        $this->budgets = FinBudget::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get()->keyBy('name')->all();

        if (!$this->adminId || !isset($this->entities[self::PARENT], $this->entities[self::CONSULT])) {
            throw new \RuntimeException('AlertsDemoSeeder needs the S1 demo data (administrator, legal entities) - run FinModelDemoSeeder first.');
        }

        $this->calculator = new RatioCalculator(null, $this->companyId);
        $this->data = new FinancialDataService($this->companyId);
        $this->actuals = new BudgetActuals($this->companyId);

        $this->cleanup();

        $rules = 0;
        $events = 0;
        $perRule = [];

        foreach (self::RULES as $key => $def) {
            $rule = $this->createRule($key, $def);
            $count = $this->history($rule, $key, $def);

            $rules++;
            $events += $count;
            $perRule[] = $count;
        }

        if ($this->command) {
            $this->command->info(sprintf('alerts: %d rules (%d active), %d events (%s)', $rules, count(array_filter(self::RULES, fn($r) => $r['off'] === null)), $events, implode(' + ', $perRule)));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Cleanup, rules
    // ---------------------------------------------------------------------------------------------

    protected function cleanup(): void
    {
        $names = array_column(self::RULES, 'name');
        $ids = FinAlertRule::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('name', $names)->pluck('id')->all();

        if ($ids) {
            FinAlertEvent::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('rule_id', $ids)->delete();
            FinAlertRule::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereIn('id', $ids)->delete();
        }
    }

    protected function createRule(string $key, array $def): FinAlertRule
    {
        $rule = new FinAlertRule();
        $rule->forceFill([
            'company_id' => $this->companyId,
            'name' => $def['name'],
            'type' => $def['type'],
            'entity_id' => $def['entity'] ? $this->entities[$def['entity']] : null,
            'params' => $this->params($def),
            'severity' => $def['severity'],
            'notify_app' => $def['app'],
            'notify_email' => $def['email'],
            'recipients' => $def['recipients'],
            'is_active' => $def['off'] === null,
            'created_by' => $this->adminId,
        ]);

        // state of the last check: an active rule was checked this morning, a switched off rule when it was still on
        $checked = Carbon::parse($def['off'] ?? self::LAST_CHECK);
        $lastClosed = $checked->copy()->startOfMonth()->subMonthNoOverflow();
        $violation = $this->evaluate($def, $lastClosed);

        $rule->last_state = $violation ? 'triggered' : 'ok';
        $rule->last_checked_at = $checked;
        $rule->created_at = $def['created'];
        $rule->updated_at = $def['off'] ?? self::LAST_CHECK;
        $rule->save();

        return $rule;
    }

    protected function params(array $def): array
    {
        switch ($def['type']) {
            case 'ratio_range':
                $ratio = $this->ratios[$def['ratio']] ?? null;

                if (!$ratio) {
                    throw new \RuntimeException("AlertsDemoSeeder: ratio '{$def['ratio']}' not found - run AnalyticsDemoSeeder first.");
                }

                return ['ratio_id' => (int)$ratio->id, 'min' => $def['min'], 'max' => $def['max']];

            case 'budget_deviation':
                $budget = $this->budgets[$def['budget']] ?? null;

                if (!$budget) {
                    throw new \RuntimeException("AlertsDemoSeeder: budget '{$def['budget']}' not found - run PlanningDemoSeeder first.");
                }

                // AFV (S8): the checker (B4) compares the metric of the rule; the demo rule is about revenue
                return ['budget_id' => (int)$budget->id, 'pct' => $def['pct'], 'metric' => $def['metric'] ?? 'revenue'];

            case 'cash_below':
            case 'receivables_above':
                return ['amount' => $def['amount']];
        }

        return [];
    }

    // ---------------------------------------------------------------------------------------------
    // History
    // ---------------------------------------------------------------------------------------------

    /** Events of the closed months March - September 2026; returns their number. */
    protected function history(FinAlertRule $rule, string $key, array $def): int
    {
        $count = 0;
        $created = Carbon::parse($def['created']);
        $off = $def['off'] ? Carbon::parse($def['off']) : null;
        $idx = array_search($key, array_keys(self::RULES), true);

        for ($month = Carbon::parse(self::FIRST_MONTH); $month->lte(Carbon::parse(self::LAST_MONTH)); $month->addMonth()) {
            $at = $month->copy()->addMonthNoOverflow()->startOfMonth()->setTime(7, 4 + 3 * $idx);

            if ($at->lt($created) || ($off && $at->gte($off))) {
                continue;
            }

            $v = $this->evaluate($def, $month->copy());

            if (!$v) {
                continue;
            }

            $acknowledged = $this->acknowledgeAt($at, $idx);
            $event = new FinAlertEvent();
            $event->forceFill([
                'company_id' => $this->companyId,
                'rule_id' => $rule->id,
                'severity' => $def['severity'],
                'message' => $v['message'],
                'value' => $v['value'],
                'threshold' => $v['threshold'],
                'period_from' => $v['from'],
                'period_to' => $v['to'],
                'context' => $v['context'],
                'notified_app' => $def['app'],
                'notified_email' => $def['email'],
                'acknowledged_at' => $acknowledged,
                'acknowledged_by' => $acknowledged ? ($idx % 2 === 0 ? $this->adminId : $this->analystId) : null,
            ]);
            $event->created_at = $at;
            $event->updated_at = $acknowledged ?: $at;
            $event->save();

            $count++;
        }

        return $count;
    }

    /**
     * Acknowledgement time (null = not acknowledged yet): the old events were acknowledged within a day,
     * of the September ones only the "liquidity" rule is still open, the October ones (month of August / September) are new.
     */
    protected function acknowledgeAt(Carbon $at, int $idx): ?Carbon
    {
        if ($at->lt(Carbon::parse('2026-09-01'))) {
            return $at->copy()->addHours(2 + 5 * ($idx % 3))->addMinutes(11 * $idx);
        }

        if ($at->lt(Carbon::parse('2026-10-01'))) {
            return $idx === 0 ? null : $at->copy()->addHours(26)->addMinutes(7 * $idx);
        }

        return null;
    }

    // ---------------------------------------------------------------------------------------------
    // Evaluation (the same logic the checker of B4 applies)
    // ---------------------------------------------------------------------------------------------

    /**
     * Evaluates the rule for the closed month; returns null when the condition is not violated, otherwise
     * value, threshold, period, Bulgarian message and context of the event.
     *
     * @return array{value: float, threshold: float, from: string, to: string, message: string, context: array}|null
     */
    protected function evaluate(array $def, Carbon $month): ?array
    {
        $from = $month->copy()->startOfMonth();
        $to = $month->copy()->endOfMonth()->startOfDay();
        $label = self::MONTHS[$month->month] . ' ' . $month->year;
        $entity = $def['entity'];
        $filters = $entity ? ['entity_ids' => [$this->entities[$entity]]] : [];
        $who = $entity ? '„' . $entity . '“' : 'групата';
        $period = $month->format('Y-m');

        switch ($def['type']) {
            case 'ratio_range':
                $ratio = $this->ratios[$def['ratio']];
                $value = $this->calculator->calculate($ratio, $from, $to, $filters);

                if ($value === null) {
                    return null;
                }

                $below = $def['min'] !== null && $value < $def['min'];
                $above = $def['max'] !== null && $value > $def['max'];

                if (!$below && !$above) {
                    return null;
                }

                $threshold = $below ? $def['min'] : $def['max'];
                $shown = $ratio->format === 'percent' ? $this->num($value, 1) . ' %' : $this->num($value, 2);
                $limit = $ratio->format === 'percent' ? $this->num($threshold, 0) . ' %' : $this->num($threshold, 2);

                return [
                    'value' => round($value, 4), 'threshold' => $threshold, 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                    'message' => sprintf('%s на %s за %s: %s – %s %s %s.', $ratio->name, $who, $label, $shown, $below ? 'под' : 'над', $below ? 'минималния праг' : 'максималния праг', $limit),
                    'context' => ['type' => 'ratio_range', 'period' => $period, 'ratio' => $def['ratio'], 'ratio_name' => $ratio->name,
                        'entity' => $entity, 'direction' => $below ? 'below' : 'above', 'min' => $def['min'], 'max' => $def['max']],
                ];

            case 'budget_deviation':
                $budget = $this->budgets[$def['budget']];
                $rows = array_filter($this->actuals->variance($budget, $month->month, $month->month), fn($r) => $r['type'] === $def['metric']);
                $planned = array_sum(array_column($rows, 'budget'));
                $actual = array_sum(array_column($rows, 'actual'));

                if ($planned <= 0) {
                    return null;
                }

                $pct = ($actual - $planned) / $planned * 100;

                if (abs($pct) <= $def['pct']) {
                    return null;
                }

                $what = $def['metric'] === 'revenue' ? 'Приходите' : 'Разходите';

                return [
                    'value' => round(abs($pct), 4), 'threshold' => $def['pct'], 'from' => $from->toDateString(), 'to' => $to->toDateString(),
                    'message' => sprintf('%s на %s за %s са с %s %% %s бюджета (план %s EUR, реализирани %s EUR) – отклонение над %s %%.',
                        $what, $who, $label, $this->num(abs($pct), 1), $pct < 0 ? 'под' : 'над', $this->num($planned, 0), $this->num($actual, 0), $this->num($def['pct'], 0)),
                    'context' => ['type' => 'budget_deviation', 'period' => $period, 'budget_id' => (int)$budget->id, 'budget' => $budget->name,
                        'metric' => $def['metric'], 'planned' => round($planned, 2), 'actual' => round($actual, 2), 'deviation_pct' => round($pct, 2)],
                ];

            case 'cash_below':
                $cash = $this->data->balanceAt($to, ['501', '503'], $filters);

                if ($cash >= $def['amount']) {
                    return null;
                }

                return [
                    'value' => round($cash, 4), 'threshold' => $def['amount'], 'from' => $to->toDateString(), 'to' => $to->toDateString(),
                    'message' => sprintf('Паричните средства на %s към %s са %s EUR – под минималния праг %s EUR.', $who, $to->format('d.m.Y'), $this->num($cash, 0), $this->num($def['amount'], 0)),
                    'context' => ['type' => 'cash_below', 'period' => $period, 'accounts' => ['501', '503'], 'entity' => $entity, 'as_of' => $to->toDateString()],
                ];

            case 'receivables_above':
                $receivables = $this->data->balanceAt($to, ['411'], $filters);

                if ($receivables <= $def['amount']) {
                    return null;
                }

                return [
                    'value' => round($receivables, 4), 'threshold' => $def['amount'], 'from' => $to->toDateString(), 'to' => $to->toDateString(),
                    'message' => sprintf('Вземанията от клиенти на %s към %s са %s EUR – над прага %s EUR.', $who, $to->format('d.m.Y'), $this->num($receivables, 0), $this->num($def['amount'], 0)),
                    'context' => ['type' => 'receivables_above', 'period' => $period, 'accounts' => ['411'], 'entity' => $entity, 'as_of' => $to->toDateString()],
                ];
        }

        return null;
    }

    /** Bulgarian number format: 1 234,5 */
    protected function num(float $value, int $decimals): string
    {
        return number_format($value, $decimals, ',', ' ');
    }

}

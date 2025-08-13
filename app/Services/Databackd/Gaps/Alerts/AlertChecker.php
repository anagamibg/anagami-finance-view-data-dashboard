<?php

namespace App\Services\Databackd\Gaps\Alerts;

use App\Mail\Databackd\FinAlertMail;
use App\Models\Company;
use App\Models\Databackd\FinAlertEvent;
use App\Models\Databackd\FinAlertRule;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Models\SmtpSetting;
use App\Models\User;
use App\Notifications\Databackd\FinAlertNotification;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Analytics\RatioCalculator;
use App\Services\Databackd\FinancialDataService;
use App\Services\Databackd\Planning\BudgetActuals;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;

/**
 * Evaluates the active alert rules (fin_alert_rules) of a company against the current data, records the fired
 * alerts (fin_alert_events) and notifies the company admins in the application (database notification) and by
 * e-mail. Works without a logged in user - used by `php artisan databackd:check-alerts` and by the
 * "Check now" button of the screen.
 *
 * What "current" means for every rule type:
 *  - ratio_range:       the calendar month of the most recent entry (month to date; during the first
 *                       MIN_DAYS_CURRENT_MONTH days of a month the previous month is used when it has data, because a few
 *                       days of turnover distort flow based ratios); entity_id null = all legal entities (simple
 *                       sums, no eliminations), else only that entity. Fires when the value is outside [min, max].
 *  - budget_deviation:  year to date of the budget year up to the last COMPLETED month that has actuals (the
 *                       running month is left out - a partial month would always look "below budget"); the budget
 *                       defines the scope (its legal entity or the whole group; rule.entity_id is ignored).
 *                       Revenue, expense and profit totals are compared (BudgetActuals::variance); the
 *                       largest |actual - budget| / |budget| fires when it is above params.pct (params.metric
 *                       revenue|expense|profit limits the comparison to one total, default any). Past budget years
 *                       are compared for the whole year, future ones are skipped.
 *  - cash_below:        balance of accounts 501-504 (with analytic accounts) at the latest balance on or before today.
 *  - receivables_above: balance of the customer accounts 411 (with analytic accounts, FinancialDataService::RECEIVABLE_CODES -
 *                       the same figure as the dashboard KPI) at the latest balance on or before today.
 *
 * Anti-spam: a rule that stays triggered does not fire again unless the value moved by SIGNIFICANT_CHANGE
 * (relative to the value of its last alert); when the value is back in the norm last_state becomes "ok" and a
 * (silent, already acknowledged) "recovered" event is written to the history.
 *
 * Event.context: {kind: triggered|recovered, type, rule_name, message_key, message_params, message_fmt, fmt,
 * currency, entity, details}; texts are rendered by AlertText in the locale of the viewer.
 */
class AlertChecker
{

    /** Relative change of the value (vs. the last alert of the rule) that fires a new alert while the rule stays triggered. */
    const SIGNIFICANT_CHANGE = 0.10;

    /** In the first days of a month the ratios use the previous month (see the class description). */
    const MIN_DAYS_CURRENT_MONTH = 7;

    const CASH_ACCOUNTS = FinancialDataService::CASH_CODES;

    /** @var array<int, FinancialDataService> */
    protected array $dataServices = [];
    /** @var array<int, RatioCalculator> */
    protected array $calculators = [];
    /** @var array<int, Company|null> */
    protected array $companies = [];

    // ------------------------------------------------------------------
    // Public API
    // ------------------------------------------------------------------

    /**
     * Checks all active rules of the company.
     *
     * @param bool $dryRun evaluate only: nothing is written, nobody is notified
     * @return array{company_id: int, dry_run: bool, checked: int, triggered: int, fired: int, recovered: int, no_data: int, errors: int, results: array<int, array>}
     *         triggered = rules that are in the triggered state now; fired = new alerts (with dry run: that would be created);
     *         every row of results is the return value of checkRule()
     */
    public function checkCompany(int $companyId, bool $dryRun = false): array
    {
        $summary = [
            'company_id' => $companyId, 'dry_run' => $dryRun, 'checked' => 0, 'triggered' => 0,
            'fired' => 0, 'recovered' => 0, 'no_data' => 0, 'errors' => 0, 'results' => [],
        ];

        $rules = FinAlertRule::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)
            ->where('is_active', true)
            ->orderBy('id')
            ->get();

        foreach ($rules as $rule) {
            $result = $this->checkRule($rule, $dryRun);

            $summary['results'][] = $result;
            $summary['checked']++;
            $summary['triggered'] += $result['status'] === 'triggered' ? 1 : 0;
            $summary['fired'] += in_array($result['action'], ['fired', 'refired'], true) ? 1 : 0;
            $summary['recovered'] += $result['action'] === 'recovered' ? 1 : 0;
            $summary['no_data'] += $result['status'] === 'no_data' ? 1 : 0;
            $summary['errors'] += $result['status'] === 'error' ? 1 : 0;
        }

        return $summary;
    }

    /**
     * Checks one rule (also an inactive one): evaluates it, updates last_state / last_checked_at, writes the alert
     * or the "recovered" record and notifies. Never throws - a failing rule is reported with status "error".
     *
     * @return array{rule_id: int, name: string, type: string, severity: string, status: string, action: string, previous_state: string, state: string,
     *         value: ?float, threshold: ?float, fmt: string, value_text: string, threshold_text: string, period_text: string,
     *         message: ?string, reason: ?string, error: ?string, event_id: ?int, notified_app: bool, notified_email: bool}
     *         status: triggered|ok|no_data|error; action: fired|refired|unchanged|recovered|none
     */
    public function checkRule(FinAlertRule $rule, bool $dryRun = false, ?Carbon $today = null): array
    {
        $company = $this->company((int)$rule->company_id);
        $today = ($today ?? Carbon::now($company?->timezone ?: config('app.timezone')))->copy()->startOfDay();
        $locale = $this->locale($company);

        try {
            $eval = $this->evaluate($rule, $today);
        } catch (\Throwable $e) {
            Log::error('databackd:check-alerts rule #' . $rule->id . ': ' . $e->getMessage(), ['exception' => $e]);
            $eval = $this->outcome('error', 'exception', ['error' => $e->getMessage()]);
        }

        $result = [
            'rule_id' => (int)$rule->id, 'name' => (string)$rule->name, 'type' => (string)$rule->type, 'severity' => (string)$rule->severity,
            'status' => $eval['status'], 'action' => 'none', 'previous_state' => (string)$rule->last_state, 'state' => (string)$rule->last_state,
            'value' => $eval['value'], 'threshold' => $eval['threshold'], 'fmt' => $eval['fmt'],
            'value_text' => '', 'threshold_text' => '',
            'period_text' => AlertText::period($eval['period_from'], $eval['period_to']),
            'message' => null, 'reason' => $eval['reason'], 'error' => $eval['error'],
            'event_id' => null, 'notified_app' => false, 'notified_email' => false,
        ];

        $currency = AlertText::companyCurrency((int)$rule->company_id);
        $result['value_text'] = $eval['value'] === null ? '—' : AlertText::format($eval['fmt'], $eval['value'], $currency);
        $result['threshold_text'] = $eval['threshold'] === null ? '—' : AlertText::format($this->thresholdFormat($eval['fmt']), $eval['threshold'], $currency);

        if ($eval['status'] === 'triggered') {
            $result['message'] = AlertText::message($this->context($rule, $eval, 'triggered', $currency), $eval['period_from'], $eval['period_to']);
        }

        // No data / failed evaluation: the state stays, only the time of the check is recorded
        if (!in_array($eval['status'], ['triggered', 'ok'], true)) {
            if (!$dryRun) {
                $this->touch($rule);
            }

            return $result;
        }

        if ($dryRun) {
            $result['action'] = $this->decide($rule, $eval);
            $result['state'] = $eval['status'];

            return $result;
        }

        $event = null;
        $action = 'none';

        DB::transaction(function () use ($rule, $eval, $currency, $locale, &$event, &$action) {
            $fresh = FinAlertRule::withoutGlobalScope(CompanyScope::class)->lockForUpdate()->find($rule->id);

            if (!$fresh) {
                return;
            }

            $action = $this->decide($fresh, $eval);

            if (in_array($action, ['fired', 'refired'], true)) {
                $event = $this->writeEvent($fresh, $eval, 'triggered', $currency, $locale);
            } elseif ($action === 'recovered') {
                $event = $this->writeEvent($fresh, $eval, 'recovered', $currency, $locale);
            }

            $fresh->forceFill(['last_state' => $eval['status'], 'last_checked_at' => now()])->save();
        });

        $result['action'] = $action;
        $result['state'] = $eval['status'];

        if ($event) {
            $result['event_id'] = (int)$event->id;

            if ($action !== 'recovered') {
                [$result['notified_app'], $result['notified_email']] = $this->notify($rule, $event, $company);
            } elseif ($result['message'] === null) {
                $result['message'] = AlertText::eventMessage($event);
            }
        }

        return $result;
    }

    /**
     * Evaluates a rule against the current data without writing anything.
     *
     * @return array{status: string, reason: ?string, error: ?string, value: ?float, threshold: ?float, fmt: string,
     *         period_from: ?Carbon, period_to: ?Carbon, message_key: ?string, message_params: array, message_fmt: array, details: array}
     *         status: triggered (the condition is met) | ok | no_data (reason says why) | error
     */
    public function evaluate(FinAlertRule $rule, ?Carbon $today = null): array
    {
        $company = $this->company((int)$rule->company_id);
        $today = ($today ?? Carbon::now($company?->timezone ?: config('app.timezone')))->copy()->startOfDay();

        switch ($rule->type) {
            case 'ratio_range':
                return $this->evaluateRatioRange($rule, $today);
            case 'budget_deviation':
                return $this->evaluateBudgetDeviation($rule, $today);
            case 'cash_below':
                return $this->evaluateCashBelow($rule, $today);
            case 'receivables_above':
                return $this->evaluateReceivablesAbove($rule, $today);
        }

        return $this->outcome('error', 'unknown_type');
    }

    /**
     * ratio_range: params {ratio_id, min, max} - fires when the ratio is below min or above max.
     */
    public function evaluateRatioRange(FinAlertRule $rule, Carbon $today): array
    {
        $companyId = (int)$rule->company_id;
        $params = (array)$rule->params;

        $ratio = FinRatio::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->find((int)($params['ratio_id'] ?? 0));

        if (!$ratio) {
            return $this->outcome('error', 'ratio_missing');
        }

        $filters = $this->filters($rule);
        $period = $this->referencePeriod($companyId, $filters, $today);

        if (!$period) {
            return $this->outcome('no_data', 'no_entries');
        }

        [$from, $to] = $period;
        $fmt = AlertText::ratioFormat($ratio);
        $value = $this->calculator($companyId)->calculate($ratio, $from, $to, $filters);

        if ($value === null) {
            return $this->outcome('no_data', 'no_value', ['fmt' => $fmt, 'period_from' => $from, 'period_to' => $to]);
        }

        $min = $this->number($params['min'] ?? null);
        $max = $this->number($params['max'] ?? null);
        $below = $min !== null && $value < $min;
        $above = $max !== null && $value > $max;
        $threshold = $below ? $min : ($above ? $max : ($min ?? $max));

        $out = [
            'value' => round($value, 4), 'threshold' => $threshold, 'fmt' => $fmt, 'period_from' => $from, 'period_to' => $to,
            'details' => ['ratio_id' => (int)$ratio->id, 'min' => $min, 'max' => $max],
        ];

        if (!$below && !$above) {
            return $this->outcome('ok', null, $out);
        }

        return $this->outcome('triggered', null, $out + [
            'message_key' => $below ? 'ratio_below' : 'ratio_above',
            'message_params' => ['name' => $ratio->name, 'value' => round($value, 4), 'threshold' => $threshold],
            'message_fmt' => ['value' => $fmt, 'threshold' => $fmt],
        ]);
    }

    /**
     * budget_deviation: params {budget_id, pct, metric?} - fires when revenue, expense or profit of the year to date
     * (or only the one in params.metric: revenue|expense|profit, default any) deviates from the budget by more than
     * pct percent (in any direction). Value = the largest deviation in percent (signed).
     */
    public function evaluateBudgetDeviation(FinAlertRule $rule, Carbon $today): array
    {
        $companyId = (int)$rule->company_id;
        $params = (array)$rule->params;
        $pct = (float)($params['pct'] ?? 0);
        $metric = in_array($params['metric'] ?? null, ['revenue', 'expense', 'profit'], true) ? $params['metric'] : 'any';

        $budget = FinBudget::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->find((int)($params['budget_id'] ?? 0));

        if (!$budget) {
            return $this->outcome('error', 'budget_missing');
        }

        $year = (int)$budget->year;

        if ($year > $today->year) {
            return $this->outcome('no_data', 'budget_future');
        }

        // last completed month (the running month is left out), but only months that have actuals
        if ($year < $today->year) {
            $toMonth = 12;
        } else {
            $isMonthEnd = $today->isSameDay($today->copy()->endOfMonth()->startOfDay());
            $toMonth = $today->month - 1 + ($isMonthEnd ? 1 : 0);
        }

        $filters = $budget->entity_id ? ['entity_ids' => [(int)$budget->entity_id]] : [];
        $last = $this->data($companyId)->query($filters)
            ->where('fin_entries.kind', 'flow')
            ->whereBetween('fin_entries.entry_date', [Carbon::create($year, 1, 1)->toDateString(), Carbon::create($year, 12, 31)->min($today)->toDateString()])
            ->max('fin_entries.entry_date');

        if (!$last) {
            return $this->outcome('no_data', 'no_entries');
        }

        $toMonth = min($toMonth, Carbon::parse($last)->month);

        if ($toMonth < 1) {
            return $this->outcome('no_data', 'no_completed_month');
        }

        $from = Carbon::create($year, 1, 1)->startOfDay();
        $to = Carbon::create($year, $toMonth, 1)->endOfMonth()->startOfDay();

        $totals = ['revenue' => ['budget' => 0.0, 'actual' => 0.0], 'expense' => ['budget' => 0.0, 'actual' => 0.0]];

        foreach ((new BudgetActuals($companyId))->variance($budget, 1, $toMonth) as $row) {
            if (isset($totals[$row['type']])) {
                $totals[$row['type']]['budget'] += $row['budget'];
                $totals[$row['type']]['actual'] += $row['actual'];
            }
        }

        $totals['profit'] = [
            'budget' => $totals['revenue']['budget'] - $totals['expense']['budget'],
            'actual' => $totals['revenue']['actual'] - $totals['expense']['actual'],
        ];

        $items = [];
        $worst = null;

        foreach ($totals as $key => $t) {
            if (abs($t['budget']) < 0.005) {
                continue;
            }

            $dev = round(($t['actual'] - $t['budget']) / abs($t['budget']) * 100, 1);
            $items[$key] = ['budget' => round($t['budget'], 2), 'actual' => round($t['actual'], 2), 'pct' => $dev];

            if (($metric === 'any' || $metric === $key) && ($worst === null || abs($dev) > abs($items[$worst]['pct']))) {
                $worst = $key;
            }
        }

        if ($worst === null) {
            return $this->outcome('no_data', 'no_budget');
        }

        $dev = $items[$worst]['pct'];

        $out = [
            'value' => $dev, 'threshold' => $pct, 'fmt' => 'pct', 'period_from' => $from, 'period_to' => $to,
            'details' => ['budget_id' => (int)$budget->id, 'metric' => $metric, 'to_month' => $toMonth, 'items' => $items],
        ];

        if (abs($dev) <= $pct) {
            return $this->outcome('ok', null, $out);
        }

        return $this->outcome('triggered', null, $out + [
            'message_key' => $dev > 0 ? 'budget_above' : 'budget_below',
            'message_params' => [
                'subject_key' => $worst, 'budget' => $budget->name, 'pct' => abs($dev),
                'planned' => $items[$worst]['budget'], 'actual' => $items[$worst]['actual'], 'threshold' => $pct,
            ],
            'message_fmt' => ['pct' => 'percent', 'threshold' => 'percent', 'planned' => 'money', 'actual' => 'money'],
        ]);
    }

    /**
     * cash_below: params {amount} - fires when cash (accounts 501-504) is below the amount.
     */
    public function evaluateCashBelow(FinAlertRule $rule, Carbon $today): array
    {
        $companyId = (int)$rule->company_id;
        $amount = (float)(((array)$rule->params)['amount'] ?? 0);
        $filters = $this->filters($rule);

        $hasBalance = $this->data($companyId)->query($filters)
            ->where('fin_entries.kind', 'balance')
            ->whereIn('fin_entries.account_id', $this->data($companyId)->cashAccountIds())
            ->where('fin_entries.entry_date', '<=', $today->toDateString())
            ->exists();

        if (!$hasBalance) {
            return $this->outcome('no_data', 'no_balance');
        }

        $cash = $this->data($companyId)->balanceAt($today, self::CASH_ACCOUNTS, $filters);

        $out = ['value' => $cash, 'threshold' => $amount, 'fmt' => 'money', 'period_from' => $today, 'period_to' => $today];

        if ($cash >= $amount) {
            return $this->outcome('ok', null, $out);
        }

        return $this->outcome('triggered', null, $out + [
            'message_key' => 'cash_below',
            'message_params' => ['value' => $cash, 'threshold' => $amount],
            'message_fmt' => ['value' => 'money', 'threshold' => 'money'],
        ]);
    }

    /**
     * receivables_above: params {amount} - fires when the receivables from customers (411 with analytic accounts) are above the amount.
     */
    public function evaluateReceivablesAbove(FinAlertRule $rule, Carbon $today): array
    {
        $companyId = (int)$rule->company_id;
        $amount = (float)(((array)$rule->params)['amount'] ?? 0);

        // review A: the same definition as the dashboard KPI "receivables from customers"
        $receivables = $this->data($companyId)->balanceAt($today, FinancialDataService::RECEIVABLE_CODES, $this->filters($rule));

        $out = ['value' => $receivables, 'threshold' => $amount, 'fmt' => 'money', 'period_from' => $today, 'period_to' => $today];

        if ($receivables <= $amount) {
            return $this->outcome('ok', null, $out);
        }

        return $this->outcome('triggered', null, $out + [
            'message_key' => 'receivables_above',
            'message_params' => ['value' => $receivables, 'threshold' => $amount],
            'message_fmt' => ['value' => 'money', 'threshold' => 'money'],
        ]);
    }

    // ------------------------------------------------------------------
    // State machine
    // ------------------------------------------------------------------

    /**
     * What a check does with the result of the evaluation:
     * fired (ok -> triggered), refired (still triggered, value changed significantly), unchanged (still triggered),
     * recovered (triggered -> ok) or none.
     */
    protected function decide(FinAlertRule $rule, array $eval): string
    {
        if ($eval['status'] === 'ok') {
            return $rule->last_state === 'triggered' ? 'recovered' : 'none';
        }

        if ($rule->last_state !== 'triggered') {
            return 'fired';
        }

        $last = $this->lastAlert($rule);

        if (!$last) {
            return 'fired';
        }

        return $this->changedSignificantly($last->value, $eval['value']) ? 'refired' : 'unchanged';
    }

    protected function changedSignificantly(?float $old, ?float $new): bool
    {
        if ($old === null || $new === null) {
            return true;
        }

        return abs($new - $old) >= max(abs($old) * self::SIGNIFICANT_CHANGE, 0.01);
    }

    /** The newest real alert (not a "recovered" record) of the rule. */
    protected function lastAlert(FinAlertRule $rule): ?FinAlertEvent
    {
        return FinAlertEvent::withoutGlobalScope(CompanyScope::class)
            ->where('rule_id', $rule->id)
            ->orderByDesc('id')
            ->limit(10)
            ->get()
            ->first(fn(FinAlertEvent $event) => ($event->context['kind'] ?? 'triggered') !== 'recovered');
    }

    protected function touch(FinAlertRule $rule): void
    {
        FinAlertRule::withoutGlobalScope(CompanyScope::class)->where('id', $rule->id)->update(['last_checked_at' => now()]);
    }

    // ------------------------------------------------------------------
    // Events
    // ------------------------------------------------------------------

    protected function writeEvent(FinAlertRule $rule, array $eval, string $kind, string $currency, string $locale): FinAlertEvent
    {
        $context = $this->context($rule, $eval, $kind, $currency);
        $message = AlertText::message($context, $eval['period_from'], $eval['period_to'], $locale) ?? $rule->name;

        $event = new FinAlertEvent();
        $event->forceFill([
            'company_id' => $rule->company_id,
            'rule_id' => $rule->id,
            'severity' => $kind === 'recovered' ? 'info' : $rule->severity,
            'message' => mb_substr($message, 0, 497) . (mb_strlen($message) > 497 ? '...' : ''),
            'value' => $eval['value'],
            'threshold' => $eval['threshold'],
            'period_from' => $eval['period_from']?->toDateString(),
            'period_to' => $eval['period_to']?->toDateString(),
            'context' => $context,
            'notified_app' => false,
            'notified_email' => false,
            // nothing to confirm when the situation is back to normal
            'acknowledged_at' => $kind === 'recovered' ? now() : null,
        ])->save();

        return $event;
    }

    /**
     * Template of the alert text + details (stored in fin_alert_events.context).
     */
    protected function context(FinAlertRule $rule, array $eval, string $kind, string $currency): array
    {
        $entity = null;

        if ($rule->entity_id && $rule->type !== 'budget_deviation') {
            $entity = FinEntity::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $rule->company_id)->find($rule->entity_id)?->name;
        }

        $context = [
            'kind' => $kind, 'type' => $rule->type, 'rule_name' => $rule->name,
            'fmt' => $eval['fmt'], 'currency' => $currency, 'entity' => $entity, 'details' => $eval['details'] ?? [],
        ];

        if ($kind === 'recovered') {
            return $context + [
                'message_key' => 'recovered',
                'message_params' => ['rule' => $rule->name, 'value' => $eval['value']],
                'message_fmt' => ['value' => $eval['fmt']],
            ];
        }

        return $context + [
            'message_key' => $eval['message_key'],
            'message_params' => $eval['message_params'],
            'message_fmt' => $eval['message_fmt'],
        ];
    }

    // ------------------------------------------------------------------
    // Notifications
    // ------------------------------------------------------------------

    /**
     * In-app notification and e-mail of an alert; marks the event notified_app / notified_email.
     *
     * @return array{0: bool, 1: bool} [in-app sent, e-mail sent]
     */
    protected function notify(FinAlertRule $rule, FinAlertEvent $event, ?Company $company): array
    {
        $sentApp = false;
        $sentMail = false;
        $admins = $this->admins((int)$rule->company_id);

        if ($rule->notify_app && $admins->isNotEmpty()) {
            try {
                Notification::sendNow($admins, new FinAlertNotification($event, (string)$rule->name));
                $sentApp = true;
            } catch (\Throwable $e) {
                Log::error('databackd:check-alerts in-app notification of rule #' . $rule->id . ': ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        if ($rule->notify_email) {
            $sentMail = $this->sendMail($rule, $event, $company, $admins);
        }

        $event->forceFill(['notified_app' => $sentApp, 'notified_email' => $sentMail])->save();

        return [$sentApp, $sentMail];
    }

    /**
     * E-mails the alert to the admins (who have e-mail notifications on) and the additional recipients of the rule.
     * Skipped when the SMTP settings are not verified (like the other AFV mails) - it would only hang.
     */
    protected function sendMail(FinAlertRule $rule, FinAlertEvent $event, ?Company $company, Collection $admins): bool
    {
        if (!$this->mailAllowed()) {
            return false;
        }

        /** @var array<string, string> $targets e-mail => locale */
        $targets = [];

        foreach ($admins as $admin) {
            if (!empty($admin->email) && $admin->email_notifications) {
                $targets[strtolower($admin->email)] = $admin->locale ?: $this->locale($company);
            }
        }

        foreach ((array)$rule->recipients as $email) {
            $email = strtolower(trim((string)$email));

            if (filter_var($email, FILTER_VALIDATE_EMAIL) && !isset($targets[$email])) {
                $targets[$email] = $this->locale($company);
            }
        }

        $sent = false;

        foreach ($targets as $email => $locale) {
            try {
                Mail::to($email)->send(new FinAlertMail($event, (string)$rule->name, (string)($company?->company_name ?? ''), $company, $locale));
                $sent = true;
            } catch (\Throwable $e) {
                Log::error('databackd:check-alerts mail of rule #' . $rule->id . ' to ' . $email . ': ' . $e->getMessage(), ['exception' => $e]);
            }
        }

        return $sent;
    }

    protected function mailAllowed(): bool
    {
        $smtp = SmtpSetting::first();

        return !($smtp && $smtp->mail_driver === 'smtp' && !$smtp->verified);
    }

    /** Active admins of the company. */
    protected function admins(int $companyId): Collection
    {
        return User::allAdmins($companyId)->filter(fn($user) => ($user->status ?? 'active') === 'active')->values();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Calendar month the ratios are calculated for (see the class description) or null when the company has no entries yet.
     *
     * @return array{0: Carbon, 1: Carbon}|null [from, to]
     */
    protected function referencePeriod(int $companyId, array $filters, Carbon $today): ?array
    {
        $data = $this->data($companyId);
        $last = $data->query($filters)->where('fin_entries.entry_date', '<=', $today->toDateString())->max('fin_entries.entry_date');

        if (!$last) {
            return null;
        }

        $monthStart = Carbon::parse($last)->startOfMonth();

        if ($monthStart->isSameMonth($today) && $today->day < self::MIN_DAYS_CURRENT_MONTH) {
            $previousStart = $monthStart->copy()->subMonthNoOverflow()->startOfMonth();
            $previousEnd = $previousStart->copy()->endOfMonth()->startOfDay();
            $hasPrevious = $data->query($filters)
                ->whereBetween('fin_entries.entry_date', [$previousStart->toDateString(), $previousEnd->toDateString()])->exists();

            if ($hasPrevious) {
                return [$previousStart, $previousEnd];
            }
        }

        return [$monthStart, $monthStart->copy()->endOfMonth()->startOfDay()->min($today)];
    }

    protected function filters(FinAlertRule $rule): array
    {
        return $rule->entity_id ? ['entity_ids' => [(int)$rule->entity_id]] : [];
    }

    /** Skeleton of an evaluation result. */
    protected function outcome(string $status, ?string $reason = null, array $extra = []): array
    {
        return array_merge([
            'status' => $status, 'reason' => $reason, 'error' => null, 'value' => null, 'threshold' => null, 'fmt' => 'ratio',
            'period_from' => null, 'period_to' => null, 'message_key' => null, 'message_params' => [], 'message_fmt' => [], 'details' => [],
        ], $extra);
    }

    /** Format of the threshold: the deviation threshold is a plain percent (the value itself is a signed percent). */
    protected function thresholdFormat(string $fmt): string
    {
        return $fmt === 'pct' ? 'percent' : $fmt;
    }

    protected function number($value): ?float
    {
        return $value === null || $value === '' ? null : (float)$value;
    }

    protected function data(int $companyId): FinancialDataService
    {
        return $this->dataServices[$companyId] ??= new FinancialDataService($companyId);
    }

    protected function calculator(int $companyId): RatioCalculator
    {
        return $this->calculators[$companyId] ??= new RatioCalculator(null, $companyId);
    }

    protected function company(int $companyId): ?Company
    {
        if (!array_key_exists($companyId, $this->companies)) {
            $this->companies[$companyId] = Company::find($companyId);
        }

        return $this->companies[$companyId];
    }

    protected function locale(?Company $company): string
    {
        return $company?->locale ?: (string)config('app.locale');
    }

}

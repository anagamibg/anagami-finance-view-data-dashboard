<?php

namespace App\Services\Databackd\Reports;

use App\Services\Databackd\FinancialDataService;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Numbers behind the financial dashboard (F8, R8): KPI cards and chart datasets.
 *
 * Everything is calculated live from fin_entries (grouped in SQL, a handful of queries per call) and works
 * without a logged in user - the tenant is passed to the constructor.
 *
 * Balance KPIs (cash, receivables, payables) only respect the legal entity filter: balance entries carry
 * no region / segment / product group / client, so those filters would always give zero.
 */
class DashboardMetrics
{

    /** Top-N rows of the counterparty table. */
    const TOP_COUNTERPARTIES = 10;

    protected int $companyId;

    protected FinancialDataService $data;

    public function __construct(?int $companyId = null)
    {
        $this->data = new FinancialDataService($companyId);
        $this->companyId = $this->data->companyId();
    }

    /**
     * KPI cards for the filter period, each with the change against the comparison period (null when
     * the filters have no comparison or the previous value is zero).
     *
     * Every item: key, value, previous (null without comparison), change (percent, or percentage points
     * for `margin`), change_unit (pct|pp), good (up|down - the direction that is good news), icon, format
     * (money|percent).
     *
     * @return array<int, array<string, mixed>>
     */
    public function kpis(ReportFilters $f): array
    {
        $filters = $f->toServiceFilters();
        $balanceFilters = array_intersect_key($filters, ['entity_ids' => 1]);
        $comparePeriod = self::comparePeriod($f);

        $current = $this->snapshot($f->from, $f->to, $filters, $balanceFilters);
        $previous = $comparePeriod ? $this->snapshot($comparePeriod['from'], $comparePeriod['to'], $filters, $balanceFilters) : null;

        $defs = [
            ['revenue', 'money', 'up', 'chart-line'],
            ['expense', 'money', 'down', 'shopping-cart'],
            ['profit', 'money', 'up', 'coins'],
            ['margin', 'percent', 'up', 'percentage'],
            ['cash', 'money', 'up', 'wallet'],
            ['receivables', 'money', 'up', 'file-invoice-dollar'],
            ['payables', 'money', 'down', 'file-invoice'],
        ];

        $out = [];

        foreach ($defs as [$key, $format, $good, $icon]) {
            $value = $current[$key];
            $prev = $previous[$key] ?? null;
            $isMargin = $key === 'margin';

            if ($prev === null || $value === null) {
                $change = null;
            } elseif ($isMargin) {
                $change = round($value - $prev, 1);
            } else {
                $change = ReportBuilder::changePct((float)$value, (float)$prev);
            }

            $out[] = [
                'key' => $key,
                'value' => $value,
                'previous' => $prev,
                'change' => $change,
                'change_unit' => $isMargin ? 'pp' : 'pct',
                'good' => $good,
                'icon' => $icon,
                'format' => $format,
            ];
        }

        return $out;
    }

    /**
     * Datasets of all seven charts.
     *
     * @return array{
     *   periods: array{labels: string[], revenue: float[], expense: float[], profit: float[]},
     *   product_groups: array{labels: string[], values: float[]},
     *   regions: array{labels: string[], values: float[]},
     *   segments: array{labels: string[], datasets: array<int, array{label: string, data: float[]}>},
     *   expenses: array{labels: string[], values: float[]},
     *   counterparties: array<int, array{name: string, revenue: float, expense: float, total: float, share: float}>,
     *   cashflow: array{labels: string[], net: float[], balance: float[]}
     * }
     */
    public function charts(ReportFilters $f): array
    {
        $filters = $f->toServiceFilters();
        $buckets = $f->buckets();

        return [
            'periods' => $this->periods($f, $buckets, $filters),
            'product_groups' => $this->revenueByDimension('product_group_id', 'product_group', $f, $filters),
            'regions' => $this->revenueByDimension('region_id', 'region', $f, $filters),
            'segments' => $this->segmentsByPeriod($f, $buckets, $filters),
            'expenses' => $this->expensesByAccount($f, $filters),
            'counterparties' => $this->topCounterparties($f, $filters),
            'cashflow' => $this->cashflow($f, $filters),
        ];
    }

    /** Whether the active filters narrow only revenue (expenses carry no region / segment / product group / client). */
    public static function filtersSplitRevenueOnly(ReportFilters $f): bool
    {
        return (bool)($f->regionId || $f->clientSegmentId || $f->productGroupId || $f->clientId);
    }

    /** Whether the active filters narrow only expenses (revenue carries no cost center). */
    public static function filtersSplitExpenseOnly(ReportFilters $f): bool
    {
        return (bool)$f->costCenterId;
    }

    /**
     * Comparison period of the dashboard. Same as ReportFilters::comparePeriod(), except that "previous" for a range of
     * whole calendar months (this month, last 3/6/12 months, this year, a custom quarter) is the same number of whole
     * months right before it - Sep 1-30 for October, Apr-Jun for Jul-Sep - instead of the same number of DAYS
     * (Aug 31 - Sep 30 for October, Mar 3 - Dec 31 for Jan-Oct).
     *
     * @return array{from: Carbon, to: Carbon}|null
     */
    public static function comparePeriod(ReportFilters $f): ?array
    {
        $from = $f->from->copy()->startOfDay();
        $to = $f->to->copy()->startOfDay();

        if ($f->compare === 'previous' && $from->day === 1 && $to->isSameDay($to->copy()->endOfMonth())) {
            $months = ($to->year - $from->year) * 12 + $to->month - $from->month + 1;
            $prevFrom = $from->copy()->subMonthsNoOverflow($months)->startOfMonth();

            return ['from' => $prevFrom, 'to' => $from->copy()->subDay()->endOfDay()];
        }

        return $f->comparePeriod();
    }

    /**
     * Date of the first flow entry (revenue / expense) of the company within the legal entity filter, or null when
     * there is none. Used to tell that the comparison period is only partly covered by data. entry_date is a plain
     * date: compare it with the period as Y-m-d (toDateString()), not as a moment - the period is in the company
     * timezone, this Carbon in the app timezone.
     */
    public function firstFlowDate(ReportFilters $f): ?Carbon
    {
        $date = $this->data->query(array_intersect_key($f->toServiceFilters(), ['entity_ids' => 1]))
            ->where('fin_entries.kind', 'flow')
            ->min('fin_entries.entry_date');

        return $date ? Carbon::parse($date)->startOfDay() : null;
    }

    /** Whether the company has any financial record at all (an empty dashboard then points to the import). */
    public function hasAnyData(): bool
    {
        return $this->data->query()->exists();
    }

    // ----- KPI helpers ---------------------------------------------------------------------------------------

    /**
     * margin is null without revenue (undefined, shown as "—" instead of a misleading 0 %).
     *
     * @return array{revenue: float, expense: float, profit: float, margin: ?float, cash: float, receivables: float, payables: float}
     */
    protected function snapshot(Carbon $from, Carbon $to, array $filters, array $balanceFilters): array
    {
        $totals = $this->data->sumByAccountType($from, $to, $filters);
        $margin = abs($totals['revenue']) >= 0.005 ? round($totals['profit'] / $totals['revenue'] * 100, 1) : null;

        return [
            'revenue' => $totals['revenue'],
            'expense' => $totals['expense'],
            'profit' => $totals['profit'],
            'margin' => $margin,
            'cash' => $this->data->balanceAt($to, FinancialDataService::CASH_CODES, $balanceFilters),
            'receivables' => $this->data->balanceAt($to, FinancialDataService::RECEIVABLE_CODES, $balanceFilters),
            'payables' => $this->data->balanceAt($to, '401', $balanceFilters),
        ];
    }

    // ----- Chart helpers -------------------------------------------------------------------------------------

    /** Chart 1: revenue / expense / profit per bucket of the chosen periodicity. */
    protected function periods(ReportFilters $f, array $buckets, array $filters): array
    {
        $expr = PeriodBuckets::sqlExpression($f->periodicity, 'fin_entries.entry_date');

        $rows = $this->flowQuery($f, $filters)
            ->selectRaw("$expr as bucket,
                SUM(CASE WHEN fin_accounts.type = 'revenue' THEN fin_entries.amount_base ELSE 0 END) as revenue,
                SUM(CASE WHEN fin_accounts.type = 'expense' THEN fin_entries.amount_base ELSE 0 END) as expense")
            ->groupByRaw($expr)
            ->get()
            ->keyBy('bucket');

        $out = ['labels' => [], 'revenue' => [], 'expense' => [], 'profit' => []];

        foreach ($buckets as $b) {
            $revenue = round((float)($rows[$b['key']]->revenue ?? 0), 2);
            $expense = round((float)($rows[$b['key']]->expense ?? 0), 2);
            $out['labels'][] = $b['label'];
            $out['revenue'][] = $revenue;
            $out['expense'][] = $expense;
            $out['profit'][] = round($revenue - $expense, 2);
        }

        return $out;
    }

    /** Charts 2 and 3: revenue per dimension value (product group, region). Rows without a value are "no dimension". */
    protected function revenueByDimension(string $column, string $type, ReportFilters $f, array $filters): array
    {
        $rows = $this->flowQuery($f, $filters, 'revenue')
            ->selectRaw("fin_entries.$column as dim_id, SUM(fin_entries.amount_base) as total")
            ->groupBy("fin_entries.$column")
            ->orderByDesc('total')
            ->get();

        $names = $this->dimensionNames($type);
        $out = ['labels' => [], 'values' => []];

        foreach ($rows as $r) {
            $out['labels'][] = $r->dim_id ? ($names[$r->dim_id] ?? '#' . $r->dim_id) : __('databackd-reports-common.dimension_none');
            $out['values'][] = round((float)$r->total, 2);
        }

        return $out;
    }

    /** Chart 4: revenue per client segment and bucket (stacked bars). */
    protected function segmentsByPeriod(ReportFilters $f, array $buckets, array $filters): array
    {
        $expr = PeriodBuckets::sqlExpression($f->periodicity, 'fin_entries.entry_date');

        $rows = $this->flowQuery($f, $filters, 'revenue')
            ->selectRaw("$expr as bucket, fin_entries.client_segment_id as dim_id, SUM(fin_entries.amount_base) as total")
            ->groupByRaw("$expr, fin_entries.client_segment_id")
            ->get();

        $names = $this->dimensionNames('client_segment');
        $sums = [];
        $cells = [];

        foreach ($rows as $r) {
            $id = (int)$r->dim_id;
            $sums[$id] = ($sums[$id] ?? 0) + (float)$r->total;
            $cells[$id][$r->bucket] = (float)$r->total;
        }

        arsort($sums);

        $datasets = [];

        foreach (array_keys($sums) as $id) {
            $data = [];

            foreach ($buckets as $b) {
                $data[] = round($cells[$id][$b['key']] ?? 0, 2);
            }

            $datasets[] = [
                'label' => $id ? ($names[$id] ?? '#' . $id) : __('databackd-reports-common.dimension_none'),
                'data' => $data,
            ];
        }

        return ['labels' => array_column($buckets, 'label'), 'datasets' => $datasets];
    }

    /** Chart 5: expenses by account (602 / 603 / 604 / 605 / 609 ...). */
    protected function expensesByAccount(ReportFilters $f, array $filters): array
    {
        $rows = $this->flowQuery($f, $filters, 'expense')
            ->selectRaw('fin_accounts.code as code, fin_accounts.name as name, SUM(fin_entries.amount_base) as total')
            ->groupBy('fin_accounts.id', 'fin_accounts.code', 'fin_accounts.name')
            ->orderByDesc('total')
            ->get();

        $out = ['labels' => [], 'values' => []];

        foreach ($rows as $r) {
            $out['labels'][] = $r->code . ' ' . $r->name;
            $out['values'][] = round((float)$r->total, 2);
        }

        return $out;
    }

    /**
     * Chart 6: top counterparties by turnover (revenue + expense), named counterparties only.
     *
     * @return array<int, array{name: string, revenue: float, expense: float, total: float, share: float}>
     */
    protected function topCounterparties(ReportFilters $f, array $filters): array
    {
        $rows = $this->flowQuery($f, $filters)
            ->whereNotNull('fin_entries.counterparty_name')
            ->where('fin_entries.counterparty_name', '!=', '')
            ->selectRaw("fin_entries.counterparty_name as name,
                SUM(CASE WHEN fin_accounts.type = 'revenue' THEN fin_entries.amount_base ELSE 0 END) as revenue,
                SUM(CASE WHEN fin_accounts.type = 'expense' THEN fin_entries.amount_base ELSE 0 END) as expense,
                SUM(fin_entries.amount_base) as total")
            ->groupBy('fin_entries.counterparty_name')
            ->orderByDesc('total')
            ->limit(self::TOP_COUNTERPARTIES)
            ->get();

        $max = (float)($rows->first()->total ?? 0);

        return $rows->map(fn($r) => [
            'name' => $r->name,
            'revenue' => round((float)$r->revenue, 2),
            'expense' => round((float)$r->expense, 2),
            'total' => round((float)$r->total, 2),
            'share' => $max > 0 ? round((float)$r->total / $max * 100, 1) : 0.0,
        ])->all();
    }

    /**
     * Chart 7: cash flow per month = change of the cash balance (501-504 with analytic accounts) over the month, plus the
     * month-end balance as a line. Monthly balances are carried forward when an entity has no entry in a month.
     * Only the legal entity filter applies (balances have no other dimensions).
     */
    protected function cashflow(ReportFilters $f, array $filters): array
    {
        $months = PeriodBuckets::make($f->from, $f->to, 'month');
        $accountIds = $this->data->cashAccountIds();

        $out = ['labels' => array_column($months, 'label'), 'net' => [], 'balance' => []];

        if (!$months || !$accountIds) {
            return $out;
        }

        $q = $this->data->query(array_intersect_key($filters, ['entity_ids' => 1]))
            ->where('fin_entries.kind', 'balance')
            ->whereIn('fin_entries.account_id', $accountIds)
            ->where('fin_entries.entry_date', '<=', end($months)['to']->toDateString())
            ->groupBy('fin_entries.entity_id', 'fin_entries.account_id', 'fin_entries.entry_date')
            ->orderBy('fin_entries.entry_date')
            ->selectRaw('fin_entries.entity_id, fin_entries.account_id, fin_entries.entry_date as d, SUM(fin_entries.amount_base) as total')
            ->get();

        // series[entity|account] = sorted [date => amount]
        $series = [];

        foreach ($q as $r) {
            $series[$r->entity_id . '|' . $r->account_id][substr((string)$r->d, 0, 10)] = (float)$r->total;
        }

        $balanceAt = function (Carbon $date) use ($series): float {
            $day = $date->toDateString();
            $sum = 0.0;

            foreach ($series as $dates) {
                $last = null;

                foreach ($dates as $d => $amount) {
                    if ($d > $day) {
                        break;
                    }

                    $last = $amount;
                }

                $sum += $last ?? 0.0;
            }

            return $sum;
        };

        $previous = $balanceAt($months[0]['from']->copy()->subDay());

        foreach ($months as $m) {
            $balance = $balanceAt($m['to']);
            $out['net'][] = round($balance - $previous, 2);
            $out['balance'][] = round($balance, 2);
            $previous = $balance;
        }

        return $out;
    }

    // ----- Query helpers -------------------------------------------------------------------------------------

    /** Flow entries of revenue / expense accounts in the period with the filters applied (joined with fin_accounts). */
    protected function flowQuery(ReportFilters $f, array $filters, ?string $type = null)
    {
        $q = $this->data->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereBetween('fin_entries.entry_date', [$f->from->toDateString(), $f->to->toDateString()]);

        return $type ? $q->where('fin_accounts.type', $type) : $q->whereIn('fin_accounts.type', ['revenue', 'expense']);
    }

    /** @return array<int, string> */
    protected function dimensionNames(string $type): array
    {
        return DB::table('fin_dimensions')->where('company_id', $this->companyId)->where('type', $type)->pluck('name', 'id')->all();
    }

}

<?php

namespace App\Services\Databackd;

use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntry;
use App\Scopes\CompanyScope;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * Read access to the universal financial model (fin_entries) shared by all AFV screens.
 *
 * All amounts are returned in the company base currency (`amount_base`) and are positive in the
 * sign convention of the account type: revenue and expense are positive numbers, profit = revenue - expense.
 * The service never relies on the global CompanyScope, so it also works from console/seeders;
 * the tenant is passed to the constructor (defaults to the logged in company).
 *
 * Common $filters keys (all optional):
 *   entity_ids        int[]  legal entities (fin_entities.id)
 *   region_id         int    fin_dimensions (type region)
 *   client_segment_id int    fin_dimensions (type client_segment)
 *   product_group_id  int    fin_dimensions (type product_group)
 *   cost_center_id    int    fin_dimensions (type cost_center)
 *   client_id         int    users.id of the client
 */
class FinancialDataService
{

    /** Dimensions usable in monthlySeries() $groupBy: key => [fk column, lookup table, name column]. */
    const GROUPS = [
        'region' => ['region_id', 'fin_dimensions', 'name'],
        'client_segment' => ['client_segment_id', 'fin_dimensions', 'name'],
        'product_group' => ['product_group_id', 'fin_dimensions', 'name'],
        'cost_center' => ['cost_center_id', 'fin_dimensions', 'name'],
        'entity' => ['entity_id', 'fin_entities', 'name'],
        'account' => ['account_id', 'fin_accounts', 'name'],
    ];

    protected int $companyId;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?? company()->id;
        ChartOfAccounts::ensureFor($this->companyId);
    }

    public function companyId(): int
    {
        return $this->companyId;
    }

    /**
     * Base query on fin_entries of the company with the filters applied.
     */
    public function query(array $filters = []): Builder
    {
        $q = FinEntry::withoutGlobalScope(CompanyScope::class)->where('fin_entries.company_id', $this->companyId);

        if (!empty($filters['entity_ids'])) {
            $q->whereIn('fin_entries.entity_id', (array)$filters['entity_ids']);
        }

        foreach (['region_id', 'client_segment_id', 'product_group_id', 'cost_center_id', 'client_id'] as $key) {
            if (!empty($filters[$key])) {
                $q->where('fin_entries.' . $key, $filters[$key]);
            }
        }

        return $q;
    }

    /**
     * Flow entries (turnover / transactions) in the period, with the `account` relation loaded.
     *
     * @param string|Carbon $from inclusive
     * @param string|Carbon $to inclusive
     * @return Collection<int, FinEntry>
     */
    public function flows($from, $to, array $filters = []): Collection
    {
        return $this->query($filters)
            ->with('account')
            ->where('kind', 'flow')
            ->whereBetween('entry_date', [$this->date($from), $this->date($to)])
            ->orderBy('entry_date')
            ->get();
    }

    /**
     * Totals for the period.
     *
     * @return array{revenue: float, expense: float, profit: float}
     */
    public function sumByAccountType($from, $to, array $filters = []): array
    {
        $rows = $this->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [$this->date($from), $this->date($to)])
            ->groupBy('fin_accounts.type')
            ->selectRaw('fin_accounts.type as type, SUM(fin_entries.amount_base) as total')
            ->pluck('total', 'type');

        $revenue = round((float)($rows['revenue'] ?? 0), 2);
        $expense = round((float)($rows['expense'] ?? 0), 2);

        return ['revenue' => $revenue, 'expense' => $expense, 'profit' => round($revenue - $expense, 2)];
    }

    /**
     * Revenue / expense / profit per month (every month of the period is present, zero-filled).
     *
     * Without $groupBy: rows [month => 'YYYY-MM', revenue, expense, profit].
     * With $groupBy (region|client_segment|product_group|cost_center|entity|account): rows
     * [month, group_id (null = not set), group_name, revenue, expense, profit].
     *
     * @return array<int, array<string, mixed>>
     */
    public function monthlySeries($from, $to, ?string $groupBy = null, array $filters = []): array
    {
        $from = $this->date($from);
        $to = $this->date($to);

        $q = $this->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [$from, $to]);

        $monthExpr = "DATE_FORMAT(fin_entries.entry_date, '%Y-%m')";
        $selects = ["$monthExpr as month",
            "SUM(CASE WHEN fin_accounts.type = 'revenue' THEN fin_entries.amount_base ELSE 0 END) as revenue",
            "SUM(CASE WHEN fin_accounts.type = 'expense' THEN fin_entries.amount_base ELSE 0 END) as expense"];
        $groups = [$monthExpr];

        $names = [];

        if ($groupBy !== null) {
            if (!isset(self::GROUPS[$groupBy])) {
                throw new \InvalidArgumentException('Unknown groupBy: ' . $groupBy);
            }

            [$column, $lookup, $nameColumn] = self::GROUPS[$groupBy];
            $selects[] = "fin_entries.$column as group_id";
            $groups[] = "fin_entries.$column";
            $names = DB::table($lookup)->where('company_id', $this->companyId)->pluck($nameColumn, 'id')->all();
        }

        $data = $q->selectRaw(implode(', ', $selects))->groupByRaw(implode(', ', $groups))->get();

        $months = [];
        for ($m = $from->copy()->startOfMonth(); $m->lte($to); $m->addMonth()) {
            $months[] = $m->format('Y-m');
        }

        $result = [];

        if ($groupBy === null) {
            $byMonth = $data->keyBy('month');

            foreach ($months as $month) {
                $result[] = $this->row($month, $byMonth[$month] ?? null);
            }

            return $result;
        }

        $byGroup = $data->groupBy(fn($r) => $r->group_id ?? 0);

        foreach ($byGroup as $groupId => $rows) {
            $byMonth = $rows->keyBy('month');

            foreach ($months as $month) {
                $result[] = array_merge(
                    ['month' => $month, 'group_id' => $groupId ?: null, 'group_name' => $groupId ? ($names[$groupId] ?? '#' . $groupId) : null],
                    array_diff_key($this->row($month, $byMonth[$month] ?? null), ['month' => 1])
                );
            }
        }

        return $result;
    }

    /**
     * Balance of accounts at a date: for every (entity, account) the latest `balance` entry on or before
     * $date is used, then summed. Contra accounts (e.g. 241 accumulated depreciation) are subtracted.
     *
     * @param string|Carbon $date
     * @param string|string[] $accounts account codes ('503' or ['501','503']), an account subtype
     *                                   ('cash', 'receivable', 'current_liability', ...) or an account type
     *                                   ('asset', 'liability', 'equity')
     * @param bool $withSubaccounts S12: a code also takes its analytic accounts - the accounts below it by parent_id
     *                              (any depth, e.g. "A1" under 503) and, for a 3-digit synthetic code, the longer codes
     *                              starting with it (5031, 503-1). Only analytic accounts of the SAME type as the code
     *                              are taken (review A: an active 4531 under the passive 453 is not added to 453).
     *                              Types and subtypes are never expanded. Every account is counted once per call, so
     *                              ['503', '5031'] does not double 5031. Pass false when the caller lists every account
     *                              itself (StatutoryReport, FormulaEngine).
     */
    public function balanceAt($date, $accounts, array $filters = [], bool $withSubaccounts = true): float
    {
        $date = $this->date($date)->toDateString();
        $accountIds = $this->accountIds($accounts, $withSubaccounts);

        if (empty($accountIds)) {
            return 0.0;
        }

        $latest = DB::table('fin_entries')
            ->where('company_id', $this->companyId)
            ->where('kind', 'balance')
            ->where('entry_date', '<=', $date)
            ->whereIn('account_id', $accountIds)
            ->groupBy('entity_id', 'account_id')
            ->selectRaw('entity_id, account_id, MAX(entry_date) as last_date');

        $rows = $this->query($filters)
            ->joinSub($latest, 'lb', function ($join) {
                $join->whereRaw('lb.entity_id <=> fin_entries.entity_id')
                    ->on('lb.account_id', '=', 'fin_entries.account_id')
                    ->on('lb.last_date', '=', 'fin_entries.entry_date');
            })
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'balance')
            ->groupBy('fin_entries.account_id')
            ->selectRaw('fin_entries.account_id as account_id, SUM(fin_entries.amount_base) as total')
            ->pluck('total', 'account_id');

        $sum = 0.0;
        // analytic accounts of a contra account (2411 / "A1" under 241) are contra too - FinAccount::contraMap()
        $contra = $rows->isNotEmpty() ? FinAccount::contraMapFor($this->companyId) : [];

        foreach ($rows as $accountId => $total) {
            $sum += ($contra[(int)$accountId] ?? false) ? -(float)$total : (float)$total;
        }

        return round($sum, 2);
    }

    /**
     * Profit and loss by account for the period.
     *
     * @return array{revenue: array, expense: array, totals: array{revenue: float, expense: float, profit: float}}
     *         revenue/expense: list of [code, name, type, subtype, amount], ordered by code
     */
    public function pnl($from, $to, array $filters = []): array
    {
        $rows = $this->query($filters)
            ->join('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->where('fin_entries.kind', 'flow')
            ->whereIn('fin_accounts.type', ['revenue', 'expense'])
            ->whereBetween('fin_entries.entry_date', [$this->date($from), $this->date($to)])
            ->groupBy('fin_accounts.id', 'fin_accounts.code', 'fin_accounts.name', 'fin_accounts.type', 'fin_accounts.subtype')
            ->orderBy('fin_accounts.code')
            ->selectRaw('fin_accounts.code, fin_accounts.name, fin_accounts.type, fin_accounts.subtype, SUM(fin_entries.amount_base) as amount')
            ->get();

        $out = ['revenue' => [], 'expense' => []];

        foreach ($rows as $r) {
            $out[$r->type][] = [
                'code' => $r->code, 'name' => $r->name, 'type' => $r->type,
                'subtype' => $r->subtype, 'amount' => round((float)$r->amount, 2),
            ];
        }

        $revenue = round(array_sum(array_column($out['revenue'], 'amount')), 2);
        $expense = round(array_sum(array_column($out['expense'], 'amount')), 2);
        $out['totals'] = ['revenue' => $revenue, 'expense' => $expense, 'profit' => round($revenue - $expense, 2)];

        return $out;
    }

    /**
     * S12: cash and bank accounts of the NSS chart - 501/502 cash on hand (BGN/foreign), 503/504 bank accounts (BGN/foreign).
     * Review A: the one definition of "cash" for the dashboard, the alerts and the forecasts (always with the analytic
     * accounts); the ratios use SUB[cash] (the formula of the user decides).
     */
    const CASH_CODES = ['501', '502', '503', '504'];

    /**
     * Review A: receivables from customers - 411 with its analytic accounts. The one definition for the dashboard KPI and
     * the receivables_above alert ("receivables from customers"); other receivables (subtype receivable: advances,
     * receivables from related parties) are in SUB[receivable] of the ratios.
     */
    const RECEIVABLE_CODES = ['411'];

    /** @return int[] ids of the cash accounts (CASH_CODES) with their analytic accounts */
    public function cashAccountIds(): array
    {
        return $this->accountIds(self::CASH_CODES, true);
    }

    /** @return int[] */
    protected function accountIds($accounts, bool $withSubaccounts = false): array
    {
        $accounts = (array)$accounts;
        $subtypes = ['current_asset', 'non_current_asset', 'cash', 'receivable', 'inventory', 'current_liability',
            'non_current_liability', 'cogs', 'payroll', 'opex', 'depreciation', 'interest', 'tax', 'other'];

        $q = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId);

        $q->where(function ($w) use ($accounts, $subtypes) {
            foreach ($accounts as $a) {
                $a = (string)$a;

                if (in_array($a, FinAccount::TYPES, true)) {
                    $w->orWhere('type', $a);
                } elseif (in_array($a, $subtypes, true)) {
                    $w->orWhere('subtype', $a);
                } else {
                    $w->orWhere('code', $a);
                }
            }
        });

        $ids = array_map('intval', $q->pluck('id')->all());

        if (!$withSubaccounts) {
            return $ids;
        }

        // Codes (not types/subtypes - those already select every account of the kind) and their analytic accounts
        $codes = array_values(array_filter(array_map('strval', $accounts), fn($a) => !in_array($a, FinAccount::TYPES, true) && !in_array($a, $subtypes, true)));

        if (!$codes) {
            return $ids;
        }

        $chart = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get(['id', 'code', 'type', 'parent_id']);
        $byCode = $chart->keyBy(fn($a) => (string)$a->code);
        $selected = array_fill_keys($ids, true);

        // Review A: an analytic account is taken only when it has the type of its synthetic account. NSS allows an active
        // analytic under a passive synthetic (4531 VAT to recover under 453 VAT payable): counting it with the parent would
        // add an asset to a liability (and TYPE[asset] + TYPE[liability] would count it twice).
        // root account id => type, for the codes asked for (the expansion starts only from them)
        $roots = [];

        foreach ($codes as $code) {
            if ($root = $byCode->get($code)) {
                $roots[(int)$root->id] = $root->type;
            }
        }

        foreach ($chart as $a) {
            if (isset($selected[$a->id])) {
                continue;
            }

            // 5031 / 503-1 under the synthetic 503 (a synthetic code missing from the chart takes them all)
            if (preg_match('/^(\d{3})./', (string)$a->code, $m) && in_array($m[1], $codes, true)) {
                $synthetic = $byCode->get($m[1]);

                if (!$synthetic || $synthetic->type === $a->type) {
                    $selected[$a->id] = true;
                    $roots[(int)$a->id] ??= $a->type;
                }
            }
        }

        // descendants by parent_id (any depth, also free codes such as "A1"), of the same type as their root
        $children = [];

        foreach ($chart as $a) {
            if ($a->parent_id) {
                $children[(int)$a->parent_id][] = $a;
            }
        }

        $stack = array_keys($roots);

        while ($stack) {
            $parentId = array_pop($stack);

            foreach ($children[$parentId] ?? [] as $child) {
                if (!isset($selected[$child->id]) && $child->type === $roots[$parentId]) {
                    $selected[(int)$child->id] = true;
                    $roots[(int)$child->id] = $roots[$parentId];
                    $stack[] = (int)$child->id;
                }
            }
        }

        return array_map('intval', array_keys($selected));
    }

    protected function row(string $month, $r): array
    {
        $revenue = round((float)($r->revenue ?? 0), 2);
        $expense = round((float)($r->expense ?? 0), 2);

        return ['month' => $month, 'revenue' => $revenue, 'expense' => $expense, 'profit' => round($revenue - $expense, 2)];
    }

    protected function date($value): Carbon
    {
        return $value instanceof Carbon ? $value->copy() : Carbon::parse($value);
    }

}

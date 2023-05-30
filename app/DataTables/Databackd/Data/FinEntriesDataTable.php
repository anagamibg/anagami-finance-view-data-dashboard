<?php

namespace App\DataTables\Databackd\Data;

use App\DataTables\BaseDataTable;
use App\Helper\Common;
use App\Models\Databackd\FinEntry;
use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\Request;
use Yajra\DataTables\Html\Column;

class FinEntriesDataTable extends BaseDataTable
{

    /**
     * Filtered fin_entries query with the joined display columns
     * (account_code, account_name, account_type, entity_name, region_name, segment_name, product_name, cost_center_name).
     * Shared by the DataTable and by FinEntriesExport so both always show the same rows.
     *
     * Request filters of the page controls: startDate, endDate (company date format), entityId, accountType, accountId,
     * kind, source, regionId, clientSegmentId, productGroupId, costCenterId, searchText ("all" or empty = no filter),
     * plus entityIds / accountIds (comma separated ids from the banner of a link with several ids).
     *
     * Query string of the links (e.g. from the P&L): from, to (Y-m-d), entity_id, account_id (one id or a comma
     * separated list). They apply only while the page control of the same filter was not sent at all, so a request of
     * the page itself (which always sends its controls, even empty) is never overridden by the address of the page.
     * An invalid date or id is ignored.
     */
    public static function filteredQuery(Request $request): Builder
    {
        $model = FinEntry::query()
            ->select(
                'fin_entries.*',
                'fin_accounts.code as account_code',
                'fin_accounts.name as account_name',
                'fin_accounts.type as account_type',
                'fin_entities.name as entity_name',
                'dim_region.name as region_name',
                'dim_segment.name as segment_name',
                'dim_product.name as product_name',
                'dim_cost.name as cost_center_name'
            )
            ->leftJoin('fin_accounts', 'fin_accounts.id', '=', 'fin_entries.account_id')
            ->leftJoin('fin_entities', 'fin_entities.id', '=', 'fin_entries.entity_id')
            ->leftJoin('fin_dimensions as dim_region', 'dim_region.id', '=', 'fin_entries.region_id')
            ->leftJoin('fin_dimensions as dim_segment', 'dim_segment.id', '=', 'fin_entries.client_segment_id')
            ->leftJoin('fin_dimensions as dim_product', 'dim_product.id', '=', 'fin_entries.product_group_id')
            ->leftJoin('fin_dimensions as dim_cost', 'dim_cost.id', '=', 'fin_entries.cost_center_id');

        $has = fn(string $key) => $request->filled($key) && $request->input($key) !== 'all' && $request->input($key) !== 'null';

        [$from, $to] = self::dateRange($request, $has);

        if ($from) {
            $model->where('fin_entries.entry_date', '>=', $from);
        }

        if ($to) {
            $model->where('fin_entries.entry_date', '<=', $to);
        }

        $equals = [
            'entityId' => 'fin_entries.entity_id',
            'accountType' => 'fin_accounts.type',
            'accountId' => 'fin_entries.account_id',
            'batchId' => 'fin_entries.import_batch_id',
            'kind' => 'fin_entries.kind',
            'source' => 'fin_entries.source',
            'regionId' => 'fin_entries.region_id',
            'clientSegmentId' => 'fin_entries.client_segment_id',
            'productGroupId' => 'fin_entries.product_group_id',
            'costCenterId' => 'fin_entries.cost_center_id',
        ];

        foreach ($equals as $param => $column) {
            if ($has($param)) {
                $model->where($column, $request->input($param));
            }
        }

        // Lists of ids: the banner of the page (entityIds / accountIds), otherwise the address of the link
        // (entity_id=1,2 / account_id=5) while the page did not send its own single-select control.
        foreach ([
            ['column' => 'fin_entries.entity_id', 'single' => 'entityId', 'list' => 'entityIds', 'url' => 'entity_id'],
            ['column' => 'fin_entries.account_id', 'single' => 'accountId', 'list' => 'accountIds', 'url' => 'account_id'],
        ] as $filter) {
            $source = $request->has($filter['list'])
                ? $request->input($filter['list'])
                : ($request->has($filter['single']) ? null : $request->input($filter['url']));

            if ($ids = self::parseIds($source)) {
                $model->whereIn($filter['column'], $ids);
            }
        }

        if ($request->filled('searchText')) {
            $term = '%' . Common::safeString($request->input('searchText')) . '%';

            $model->where(function ($query) use ($term) {
                $query->where('fin_entries.counterparty_name', 'like', $term)
                    ->orWhere('fin_entries.document_no', 'like', $term)
                    ->orWhere('fin_entries.description', 'like', $term)
                    ->orWhere('fin_accounts.code', 'like', $term)
                    ->orWhere('fin_accounts.name', 'like', $term);
            });
        }

        return $model;
    }

    /**
     * Period as [from, to] in Y-m-d (null = open end): the date range control of the page (company format, sent
     * even when empty) or, when the page sent none, from / to of the link.
     */
    protected static function dateRange(Request $request, \Closure $has): array
    {
        if ($request->has('startDate') || $request->has('endDate')) {
            // S6/P3: a malformed date is ignored (null = open end) instead of ending in a 500
            $toYmd = function ($value) {
                try {
                    return is_string($value) ? companyToDateString($value) : null;
                } catch (\Throwable $e) {
                    return null;
                }
            };

            return [
                $has('startDate') ? $toYmd($request->input('startDate')) : null,
                $has('endDate') ? $toYmd($request->input('endDate')) : null,
            ];
        }

        $from = self::validDate($request->input('from'));
        $to = self::validDate($request->input('to'));

        return $from && $to && $from > $to ? [$to, $from] : [$from, $to];
    }

    /**
     * A real calendar date in Y-m-d (2026-02-31 and any other format are rejected), otherwise null.
     */
    public static function validDate($value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            $date = Carbon::createFromFormat('!Y-m-d', $value);
        } catch (\Throwable $e) {
            return null;
        }

        return $date && $date->format('Y-m-d') === $value ? $value : null;
    }

    /**
     * Positive integer ids from "5", "1,2,3", [1, 2] or ["1,2"]; anything else is dropped (at most 200 ids).
     *
     * @return int[]
     */
    public static function parseIds($value): array
    {
        $ids = [];

        foreach ((array)$value as $item) {
            if (!is_scalar($item)) {
                continue;
            }

            foreach (explode(',', (string)$item) as $part) {
                $part = trim($part);

                if ($part !== '' && ctype_digit($part) && (int)$part > 0) {
                    $ids[] = (int)$part;
                }
            }
        }

        return array_slice(array_values(array_unique($ids)), 0, 200);
    }

    public static function money($value, ?string $currency = null): string
    {
        $text = number_format((float)$value, 2, ',', "\u{00A0}");

        return $currency ? $text . "\u{00A0}" . $currency : $text;
    }

    public function dataTable($query)
    {
        $baseCurrency = company()->currency->currency_code ?? 'EUR';
        $totalsQuery = clone $query;

        $datatables = datatables()->eloquent($query);
        $datatables->addIndexColumn();
        $datatables->addColumn('check', fn($row) => $this->checkBox($row));

        $datatables->addColumn('action', function ($row) {
            return '<div class="task_view">
                <div class="dropdown">
                    <a class="task_view_more d-flex align-items-center justify-content-center dropdown-toggle" type="link"
                        id="dropdownMenuLink-' . $row->id . '" data-toggle="dropdown" aria-haspopup="true" aria-expanded="false">
                        <i class="icon-options-vertical icons"></i>
                    </a>
                    <div class="dropdown-menu dropdown-menu-right" aria-labelledby="dropdownMenuLink-' . $row->id . '" tabindex="0">
                        <a class="dropdown-item openRightModal" href="' . route('databackd.data.entries.edit', [$row->id]) . '">
                            <i class="fa fa-edit mr-2"></i>' . e(__('databackd-data-entries.actions.edit')) . '
                        </a>
                        <a class="dropdown-item delete-table-row" href="javascript:;" data-entry-id="' . $row->id . '" data-source="' . e($row->source) . '">
                            <i class="fa fa-trash mr-2"></i>' . e(__('databackd-data-entries.actions.delete_one')) . '
                        </a>
                    </div>
                </div>
            </div>';
        });

        $datatables->editColumn('entry_date', fn($row) => $row->entry_date?->translatedFormat($this->company->date_format));

        $datatables->editColumn('entity_name', fn($row) => e($row->entity_name ?? '--'));

        $datatables->addColumn('account', function ($row) {
            return '<span class="f-w-500 text-dark">' . e($row->account_code) . '</span> '
                . '<span class="text-dark-grey">' . e($row->account_name) . '</span>'
                . '<div class="f-11 text-lightest">' . e(__('databackd-data-common.account_types.' . $row->account_type)) . '</div>';
        });

        $datatables->editColumn('kind', function ($row) {
            $class = $row->kind === 'balance' ? 'badge-secondary' : 'badge-info';

            return '<span class="badge ' . $class . '">' . e(__('databackd-data-common.kinds.' . $row->kind)) . '</span>';
        });

        $datatables->editColumn('amount', function ($row) use ($baseCurrency) {
            // the currency is shown inside raw HTML: manual entries accept any 3 characters (size:3), so escape it
            $text = '<span class="f-w-500 text-dark">' . self::money($row->amount, $row->currency === null ? null : e($row->currency)) . '</span>';

            if ($row->currency !== $baseCurrency) {
                $text .= '<div class="f-11 text-lightest">' . self::money($row->amount_base, $baseCurrency) . '</div>';
            }

            return $text;
        });

        $datatables->editColumn('region_name', fn($row) => e($row->region_name ?? '--'));
        $datatables->editColumn('segment_name', fn($row) => e($row->segment_name ?? '--'));
        $datatables->editColumn('product_name', fn($row) => e($row->product_name ?? '--'));
        $datatables->editColumn('cost_center_name', fn($row) => e($row->cost_center_name ?? '--'));

        $datatables->editColumn('counterparty_name', function ($row) {
            $text = e($row->counterparty_name ?? '--');

            if ($row->document_no) {
                $text .= '<div class="f-11 text-lightest">' . e($row->document_no) . '</div>';
            }

            return $text;
        });

        $datatables->editColumn('source', function ($row) {
            $classes = [
                'manual' => 'badge-primary',
                'excel' => 'badge-success',
                'csv' => 'badge-success',
                'google_sheets' => 'badge-info',
                'api' => 'badge-dark',
                'invoice' => 'badge-warning',
                'expense' => 'badge-warning',
                'bank_statement' => 'badge-secondary',
                'demo' => 'badge-light border',
            ];

            return '<span class="badge ' . ($classes[$row->source] ?? 'badge-light') . '">'
                . e(__('databackd-data-common.sources.' . $row->source)) . '</span>';
        });

        $datatables->filterColumn('account', function ($query, $keyword) {
            $query->where(function ($q) use ($keyword) {
                $q->where('fin_accounts.code', 'like', '%' . $keyword . '%')
                    ->orWhere('fin_accounts.name', 'like', '%' . $keyword . '%');
            });
        });
        $datatables->orderColumn('account', 'fin_accounts.code $1');

        $datatables->with('totals', $this->totals($totalsQuery, $baseCurrency));
        $datatables->smart(false);
        \App\DataTables\Databackd\ColumnWhitelist::apply($datatables, $this->getColumns()); // S6/P3: search / order only by the declared columns
        $datatables->setRowId(fn($row) => 'row-' . $row->id);

        $datatables->rawColumns(['action', 'check', 'account', 'kind', 'amount', 'counterparty_name', 'source', 'entity_name', 'region_name', 'segment_name', 'product_name', 'cost_center_name']);

        return $datatables;
    }

    /**
     * Totals (base currency) over all rows matching the filters, not only the current page.
     * S12/A07: "total" is the result of the filtered turnovers (revenue minus expense accounts) - before it was the sum of
     * every amount (revenue + expense + balances), a number without meaning.
     */
    protected function totals(Builder $query, string $baseCurrency): array
    {
        $rows = $query->toBase()->reorder()->select('fin_entries.kind', 'fin_accounts.type as account_type')
            ->selectRaw('SUM(fin_entries.amount_base) as total, COUNT(*) as cnt')
            ->groupBy('fin_entries.kind', 'fin_accounts.type')
            ->get();

        $sum = fn(callable $filter, string $field = 'total') => $rows->filter($filter)->sum(fn($row) => (float)$row->{$field});

        $flow = $sum(fn($row) => $row->kind === 'flow');
        $balance = $sum(fn($row) => $row->kind === 'balance');
        $result = $sum(fn($row) => $row->kind === 'flow' && $row->account_type === 'revenue')
            - $sum(fn($row) => $row->kind === 'flow' && $row->account_type === 'expense');

        return [
            'total' => self::money($result, $baseCurrency),
            'flow' => self::money($flow, $baseCurrency),
            'balance' => self::money($balance, $baseCurrency),
            'count' => number_format((int)$sum(fn() => true, 'cnt'), 0, ',', "\u{00A0}"),
        ];
    }

    public function query()
    {
        return self::filteredQuery(request());
    }

    public function html()
    {
        return $this->setBuilder('fin-entries-table', 2);
    }

    protected function getColumns()
    {
        $t = fn(string $key) => __('databackd-data-entries.columns.' . $key);

        $data = [
            'check' => [
                'title' => '<input type="checkbox" name="select_all_table" id="select-all-table" onclick="selectAllTable(this)">',
                'exportable' => false,
                'orderable' => false,
                'searchable' => false,
            ],
            '#' => ['data' => 'DT_RowIndex', 'orderable' => false, 'searchable' => false, 'title' => '#'],
            $t('date') => ['data' => 'entry_date', 'name' => 'fin_entries.entry_date', 'title' => $t('date')],
            $t('entity') => ['data' => 'entity_name', 'name' => 'fin_entities.name', 'title' => $t('entity')],
            // S12/A07: the styles of the list target these classes, not the column positions (td:nth-child)
            $t('account') => ['data' => 'account', 'name' => 'account', 'title' => $t('account'), 'className' => 'dbk-col-account'],
            $t('kind') => ['data' => 'kind', 'name' => 'fin_entries.kind', 'title' => $t('kind')],
            $t('amount') => ['data' => 'amount', 'name' => 'fin_entries.amount_base', 'title' => $t('amount'), 'className' => 'text-right dbk-col-amount'],
            $t('region') => ['data' => 'region_name', 'name' => 'dim_region.name', 'title' => $t('region')],
            $t('client_segment') => ['data' => 'segment_name', 'name' => 'dim_segment.name', 'title' => $t('client_segment')],
            $t('product_group') => ['data' => 'product_name', 'name' => 'dim_product.name', 'title' => $t('product_group')],
            $t('cost_center') => ['data' => 'cost_center_name', 'name' => 'dim_cost.name', 'title' => $t('cost_center')],
            $t('counterparty') => ['data' => 'counterparty_name', 'name' => 'fin_entries.counterparty_name', 'title' => $t('counterparty'), 'className' => 'dbk-col-counterparty'],
            $t('source') => ['data' => 'source', 'name' => 'fin_entries.source', 'title' => $t('source')],
        ];

        $action = [
            Column::computed('action', $t('action'))
                ->exportable(false)
                ->printable(false)
                ->orderable(false)
                ->searchable(false)
                ->addClass('text-right pr-20'),
        ];

        return array_merge($data, $action);
    }

}

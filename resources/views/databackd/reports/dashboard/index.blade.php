@extends('layouts.app')

@section('content')
    @php
        // NBSP keeps "1 234,56 €" on one line; a negative amount gets the minus sign "−" (ui-guidelines §7)
        $currencySign = $currencyCode === 'EUR' ? '€' : $currencyCode;
        $money = fn($v) => ((float)$v < 0 ? '−' : '') . number_format(abs((float)$v), 2, ',', "\u{00A0}") . "\u{00A0}" . $currencySign;
        $kpiByKey = collect($kpis)->keyBy('key');
        $periodEmpty = $hasAnyData && abs($kpiByKey['revenue']['value']) < 0.005 && abs($kpiByKey['expense']['value']) < 0.005;

        $kpiInfo = ['cash' => 'cash_info', 'receivables' => 'receivables_info', 'payables' => 'payables_info', 'margin' => 'margin_info'];
        // Icon tone (semantic colour of the KPI icon) and sparkline source per KPI
        $kpiTone = ['revenue' => 'revenue', 'expense' => 'expense', 'profit' => 'profit', 'margin' => 'profit', 'cash' => 'cash'];
        $kpiSpark = ['revenue', 'expense', 'profit', 'margin', 'cash'];
        $kpiRows = [array_slice($kpis, 0, 4), array_slice($kpis, 4)];
        $cp = $charts['counterparties'];
    @endphp

    <div class="content-wrapper fin-dash dbk-dash">
        @include('databackd.gaps.onboarding._banner') {{-- AFV (S8/B5): setup wizard banner, shows itself only while needed --}}
        {{-- Heading, data timestamp and refresh --}}
        <x-dbk.page-header :title="__('databackd-reports-dashboard.title')" :subtitle="__('databackd-reports-dashboard.subtitle')">
            <x-slot name="actions">
                <span class="dbk-dash-asof" id="dashboard-data-as-of">
                    <i class="fa fa-clock mr-1"></i>@lang('databackd-reports-dashboard.data_as_of'): <strong>{{ $dataAsOf }}</strong>
                </span>
                <x-forms.button-primary icon="sync-alt" id="dashboard-refresh">@lang('databackd-reports-dashboard.refresh')</x-forms.button-primary>
            </x-slot>
        </x-dbk.page-header>

        @unless ($hasAnyData)
            {{-- No records at all (new company): one clear next step instead of zero KPIs and seven empty charts --}}
            <x-dbk.card class="mb-3">
                <x-dbk.empty icon="bar-chart-line" :title="__('databackd-reports-dashboard.empty.title')" :text="__('databackd-reports-dashboard.empty.text')">
                    <div class="dbk-dash-empty-actions">
                        @if ($importUrl)
                            <x-forms.link-primary :link="$importUrl" icon="upload">@lang('databackd-reports-dashboard.empty.import')</x-forms.link-primary>
                        @endif
                        @if ($entriesUrl)
                            <x-forms.link-secondary :link="$entriesUrl" icon="plus">@lang('databackd-reports-dashboard.empty.manual')</x-forms.link-secondary>
                        @endif
                        @if ($onboardingUrl)
                            <x-forms.link-secondary :link="$onboardingUrl" icon="flag-checkered">@lang('databackd-reports-dashboard.empty.setup')</x-forms.link-secondary>
                        @endif
                    </div>
                </x-dbk.empty>
            </x-dbk.card>
        @else
        {{-- the shared filter bar folds its fields behind "Filters" on a phone by itself --}}
        @include('databackd.reports._filters', [
            'filters' => $filters,
            'options' => ['period', 'periodicity', 'entities', 'dimensions', 'client', 'compare'],
        ])

        <div class="dbk-dash-note">
            @if ($comparePeriod)
                <i class="fa fa-exchange-alt mr-1"></i>{{ __('databackd-reports-dashboard.compared_with', ['from' => $comparePeriod['from']->format('d.m.Y'), 'to' => $comparePeriod['to']->format('d.m.Y')]) }}
            @else
                <i class="fa fa-minus-circle mr-1"></i>@lang('databackd-reports-dashboard.no_comparison')
            @endif
            <span class="mx-1">·</span>@lang('databackd-reports-dashboard.balance_note')
            @if ($comparePartialFrom)
                <div class="dbk-text-warning mt-1"><i class="fa fa-info-circle mr-1"></i>{{ __('databackd-reports-dashboard.compare_partial', ['date' => $comparePartialFrom->format('d.m.Y')]) }}</div>
            @endif
            @if ($expensesNotSplit)
                <div class="dbk-text-warning mt-1"><i class="fa fa-info-circle mr-1"></i>@lang('databackd-reports-dashboard.expenses_not_split')</div>
            @endif
            @if ($revenueNotSplit)
                <div class="dbk-text-warning mt-1"><i class="fa fa-info-circle mr-1"></i>@lang('databackd-reports-dashboard.revenue_not_split')</div>
            @endif
        </div>

        @if ($periodEmpty)
            @php
                // narrowed by a legal entity / dimension / client: offer to clear those, otherwise to widen the period
                $narrowed = (bool)$filters->toServiceFilters();
                $periodOnly = array_intersect_key($filters->toQuery(), array_flip(['period', 'from', 'to', 'periodicity', 'compare']));
            @endphp
            <div class="dbk-dash-period-empty mb-3" role="status">
                <i class="fa fa-info-circle mr-1" aria-hidden="true"></i>@lang('databackd-reports-dashboard.' . ($narrowed ? 'period_empty_filtered' : 'period_empty'))
                @if ($narrowed)
                    <a href="{{ route('databackd.reports.dashboard.index', $periodOnly) }}" class="ml-1">@lang('databackd-reports-dashboard.period_empty_clear')</a>
                @else
                    <a href="{{ route('databackd.reports.dashboard.index', ['period' => 'last_12_months']) }}" class="ml-1">@lang('databackd-reports-dashboard.period_empty_action')</a>
                @endif
            </div>
        @endif

        {{-- KPI cards: P&L first row, balances second row --}}
        @foreach ($kpiRows as $rowIndex => $row)
            <div @class(['dbk-kpi-grid', 'dbk-kpi-grid--3 dbk-dash-kpi-balances' => $rowIndex === 1])>
                @foreach ($row as $kpi)
                    @php
                        $value = $kpi['value'] === null
                            ? '—'
                            : ($kpi['format'] === 'percent'
                                ? ((float)$kpi['value'] < 0 ? '−' : '') . number_format(abs($kpi['value']), 1, ',', "\u{00A0}") . "\u{00A0}%"
                                : $money($kpi['value']));
                        $info = isset($kpiInfo[$kpi['key']]) ? __('databackd-reports-dashboard.kpi.' . $kpiInfo[$kpi['key']]) : null;
                    @endphp
                    <x-dbk.kpi
                        :label="__('databackd-reports-dashboard.kpi.' . $kpi['key'])"
                        :value="$value"
                        :icon="$kpi['icon']"
                        :tone="$kpiTone[$kpi['key']] ?? null"
                        :change="$comparePeriod ? $kpi['change'] : null"
                        :change-unit="$kpi['change_unit'] === 'pp' ? __('databackd-reports-dashboard.pp') : '%'"
                        :good="$kpi['good']"
                        :hint="$comparePeriod && $kpi['change'] !== null ? __('databackd-reports-dashboard.' . ($filters->compare === 'last_year' ? 'vs_last_year' : 'vs_previous')) : null"
                        :info="$info"
                        :spark-id="in_array($kpi['key'], $kpiSpark) ? 'kpi-spark-' . $kpi['key'] : null"
                        :value-id="'kpi-' . $kpi['key']"
                    />
                @endforeach
            </div>
        @endforeach

        {{-- Trends → structure → breakdowns --}}
        <div class="dbk-grid mb-3">
            <x-dbk.card class="dbk-span-8" :title="__('databackd-reports-dashboard.charts.periods')">
                <x-dbk.chart id="chart-periods" size="lg" :label="__('databackd-reports-dashboard.charts.periods')" />
            </x-dbk.card>

            <x-dbk.card class="dbk-span-4" :title="__('databackd-reports-dashboard.charts.expenses')">
                <x-dbk.chart id="chart-expenses" size="lg" :label="__('databackd-reports-dashboard.charts.expenses')" />
            </x-dbk.card>

            <x-dbk.card class="dbk-span-8" :title="__('databackd-reports-dashboard.charts.cashflow')" :subtitle="__('databackd-reports-dashboard.charts.cashflow_note')">
                <p class="dbk-dash-chart-label">@lang('databackd-reports-dashboard.charts.cashflow_balance')</p>
                <x-dbk.chart id="chart-cashflow" size="sm" :label="__('databackd-reports-dashboard.charts.cashflow_balance')" />
                <p class="dbk-dash-chart-label mt-3">@lang('databackd-reports-dashboard.charts.cashflow_net')</p>
                <x-dbk.chart id="chart-cashflow-net" size="xs" :label="__('databackd-reports-dashboard.charts.cashflow_net')" />
            </x-dbk.card>

            <x-dbk.card class="dbk-span-4" :title="__('databackd-reports-dashboard.charts.product_groups')">
                <x-dbk.chart id="chart-product-groups" size="lg" :label="__('databackd-reports-dashboard.charts.product_groups')" />
            </x-dbk.card>

            <x-dbk.card class="dbk-span-4" :title="__('databackd-reports-dashboard.charts.regions')">
                <x-dbk.chart id="chart-regions" :label="__('databackd-reports-dashboard.charts.regions')" />
            </x-dbk.card>

            <x-dbk.card class="dbk-span-8" :title="__('databackd-reports-dashboard.charts.segments')">
                <x-dbk.chart id="chart-segments" :label="__('databackd-reports-dashboard.charts.segments')" />
            </x-dbk.card>
        </div>

        {{-- Top counterparties --}}
        <x-dbk.card class="mb-3" :title="__('databackd-reports-dashboard.charts.counterparties')" body-class="p-0">
            @if (count($cp))
                <div class="dbk-table-wrap is-flush">
                    <table class="dbk-table" id="counterparties-table">
                        <thead>
                        <tr>
                            <th class="num dbk-dash-rank">#</th>
                            <th>@lang('databackd-reports-dashboard.counterparties.name')</th>
                            <th class="num">@lang('databackd-reports-dashboard.counterparties.revenue')</th>
                            <th class="num">@lang('databackd-reports-dashboard.counterparties.expense')</th>
                            <th class="num">@lang('databackd-reports-dashboard.counterparties.turnover')</th>
                            <th class="dbk-dash-share"></th>
                        </tr>
                        </thead>
                        <tbody>
                        @foreach ($cp as $i => $row)
                            @php
                                $revShare = $row['total'] > 0 ? $row['share'] * $row['revenue'] / $row['total'] : 0;
                                $expShare = $row['share'] - $revShare;
                            @endphp
                            <tr>
                                <td class="num dbk-text-muted">{{ $i + 1 }}</td>
                                <td class="dbk-dash-cp-name">{{ $row['name'] }}</td>
                                <td class="num">{{ $money($row['revenue']) }}</td>
                                <td class="num">{{ $money($row['expense']) }}</td>
                                <td class="num font-weight-bold">{{ $money($row['total']) }}</td>
                                <td>
                                    <div class="dbk-progress">
                                        <span class="is-revenue" style="width: {{ round($revShare, 1) }}%"></span>
                                        <span class="is-expense" style="width: {{ round($expShare, 1) }}%"></span>
                                    </div>
                                </td>
                            </tr>
                        @endforeach
                        </tbody>
                    </table>
                </div>
            @else
                <x-dbk.empty icon="people" :title="__('databackd-reports-common.no_data')" />
            @endif
        </x-dbk.card>
        @endunless
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('[data-toggle="popover"]').popover();

            if (!window.dbkChart || !@json($hasAnyData)) return;

            window.dbkCurrency = @json($currencyCode);
            var charts = @json($charts);
            var labels = {
                revenue: @json(__('databackd-reports-dashboard.charts.revenue')),
                expense: @json(__('databackd-reports-dashboard.charts.expense')),
                profit: @json(__('databackd-reports-dashboard.charts.profit')),
                cashNet: @json(__('databackd-reports-dashboard.charts.cashflow_net')),
                cashBalance: @json(__('databackd-reports-dashboard.charts.cashflow_balance')),
                noData: @json(__('databackd-reports-common.no_data'))
            };

            // Folds {labels, values} into top N (+ "Други") for donuts / ranked bars
            var fold = function (src, n) {
                var items = (src.labels || []).map(function (l, i) { return {label: l, value: Number(src.values[i]) || 0}; });
                var top = n ? dbkChart.topN(items, n) : items.slice().sort(function (a, b) { return b.value - a.value; });
                return {labels: top.map(function (x) { return x.label; }), values: top.map(function (x) { return x.value; })};
            };

            var render = function () {
                var p = charts.periods;
                var c = charts.cashflow;

                // KPI sparklines (monthly / periodic series of the dashboard)
                var margin = p.revenue.map(function (r, i) { return r ? Math.round(p.profit[i] / r * 1000) / 10 : null; });
                dbkChart.sparkline('kpi-spark-revenue', {data: p.revenue, labels: p.labels, role: 'revenue'});
                dbkChart.sparkline('kpi-spark-expense', {data: p.expense, labels: p.labels, role: 'expense'});
                dbkChart.sparkline('kpi-spark-profit', {data: p.profit, labels: p.labels, role: 'profit'});
                dbkChart.sparkline('kpi-spark-margin', {data: margin, labels: p.labels, role: 'profit', unit: 'percent'});
                dbkChart.sparkline('kpi-spark-cash', {data: c.balance, labels: c.labels, role: 'cash'});

                // Revenue vs expenses (bars) + profit (line)
                dbkChart.combo('chart-periods', {
                    labels: p.labels,
                    bars: [
                        {label: labels.revenue, data: p.revenue, role: 'revenue'},
                        {label: labels.expense, data: p.expense, role: 'expense'}
                    ],
                    lines: [{label: labels.profit, data: p.profit, role: 'profit'}],
                    emptyText: labels.noData
                });

                // Structure donuts (≤ 6 slices)
                var exp = fold(charts.expenses, 6);
                dbkChart.donut('chart-expenses', {labels: exp.labels, data: exp.values, emptyText: labels.noData});
                var pg = fold(charts.product_groups, 6);
                dbkChart.donut('chart-product-groups', {labels: pg.labels, data: pg.values, emptyText: labels.noData});

                // Cash: month-end balance (area) and net change (bars) — two scales, two charts
                dbkChart.line('chart-cashflow', {
                    labels: c.labels,
                    datasets: [{label: labels.cashBalance, data: c.balance, role: 'cash', area: true}],
                    emptyText: labels.noData
                });
                dbkChart.bar('chart-cashflow-net', {
                    labels: c.labels,
                    datasets: [{
                        label: labels.cashNet, data: c.net,
                        colors: c.net.map(function (v) { return dbkChart.role(v >= 0 ? 'revenue' : 'expense'); })
                    }],
                    emptyText: labels.noData
                });

                // Revenue by region: horizontal bars, sorted desc
                var rg = fold(charts.regions, 0);
                dbkChart.bar('chart-regions', {
                    labels: rg.labels,
                    datasets: [{label: labels.revenue, data: rg.values, role: 'revenue'}],
                    horizontal: true,
                    emptyText: labels.noData
                });

                // Revenue by customer segment (stacked, categorical colours)
                var s = charts.segments;
                dbkChart.bar('chart-segments', {
                    labels: s.labels,
                    datasets: s.datasets.map(function (d) { return {label: d.label, data: d.data}; }),
                    stacked: true,
                    emptyText: labels.noData
                });
            };

            render();

            // Refresh: reload with the same filters (data is calculated live)
            $('#dashboard-refresh').on('click', function () {
                window.location.reload();
            });
        });
    </script>
@endpush

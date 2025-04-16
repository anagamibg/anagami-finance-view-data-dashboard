@extends('layouts.app')

@php
    $t = fn(string $key, array $r = []) => __('databackd-gaps-fx-rates.' . $key, $r);
    // Rates keep up to 8 decimals in the table; show at least 4, trailing zeros beyond that are dropped
    $fmtRate = function ($value) {
        $text = rtrim(rtrim(number_format((float)$value, 8, '.', ''), '0'), '.');
        $decimals = max(4, strlen(explode('.', $text . '.')[1]));

        return number_format((float)$value, $decimals, ',', ' ');
    };
@endphp

@section('content')
    <div class="content-wrapper">
        <p class="f-14 text-dark-grey mb-1">{{ $t('help') }}</p>
        <p class="f-13 text-lightest mb-3">{{ $t('company_currency', ['currency' => $companyCurrency]) }}</p>

        {{-- Summary --}}
        <div class="row mb-4">
            <div class="col-6 col-xl-3 mb-3 mb-xl-0">
                <x-cards.widget :title="$t('widgets.total')" :value="number_format($totalRates, 0, ',', ' ')" icon="exchange-alt"/>
            </div>
            <div class="col-6 col-xl-3 mb-3 mb-xl-0">
                <x-cards.widget :title="$t('widgets.currencies')" :value="$currencyCount" icon="coins"/>
            </div>
            {{-- the dates do not fit half of a phone screen --}}
            <div class="col-12 col-sm-6 col-xl-3 mb-3 mb-sm-0">
                <x-cards.widget :title="$t('widgets.latest')" :value="$lastDate ?? '—'" icon="calendar-alt"/>
            </div>
            <div class="col-12 col-sm-6 col-xl-3">
                <x-cards.widget :title="$t('widgets.last_ecb')" :value="$lastEcbDate ?? '—'" icon="university"/>
            </div>
        </div>

        {{-- ECB --}}
        <x-cards.data class="mb-4" :title="$t('ecb.title')">
            <div class="d-flex flex-wrap align-items-center justify-content-between">
                <div class="mb-3 mb-lg-0 pr-lg-4" style="flex: 1 1 380px">
                    <p class="f-13 text-dark-grey mb-1">{{ $t('ecb.description') }}</p>
                    <p class="f-12 text-lightest mb-0">{{ $t('ecb.note') }}</p>
                </div>
                <div class="d-flex flex-wrap align-items-center">
                    <div class="custom-control custom-checkbox mr-3 mb-2 mb-sm-0">
                        <input type="checkbox" class="custom-control-input" id="fx-overwrite-manual" value="1">
                        <label class="custom-control-label f-13 text-dark-grey" for="fx-overwrite-manual">{{ $t('ecb.overwrite_manual') }}</label>
                    </div>
                    <x-forms.button-primary id="fx-fetch-ecb" icon="cloud-download-alt">
                        {{ $t('ecb.button') }}
                    </x-forms.button-primary>
                </div>
            </div>

            <div id="fx-ecb-result" class="mt-3" aria-live="polite">
                @if ($ecbResult)
                    @include('databackd.gaps.fx-rates._ecb-result', ['result' => $ecbResult])
                @endif
            </div>
        </x-cards.data>

        <div class="row">
            {{-- List --}}
            <div class="col-xl-8 mb-4 mb-xl-0">
                <x-cards.data :title="$t('list.title')" padding="false" id="fx-list-card">
                    <x-slot name="action">
                        <x-forms.button-primary id="fx-add-rate" icon="plus">{{ $t('list.add') }}</x-forms.button-primary>
                    </x-slot>

                    <form method="GET" action="{{ route('databackd.gaps.fx-rates.index') }}" id="fx-filter-form"
                          class="d-flex flex-wrap align-items-end dbk-fx-filters pl-20 pr-20 py-3 border-bottom">
                        <div class="mr-3 mb-2">
                            <label for="fx-filter-currency" class="f-12 text-lightest">{{ $t('list.currency') }}</label>
                            <select name="currency" id="fx-filter-currency" class="form-control height-35 f-14">
                                <option value="">{{ $t('list.all_currencies') }}</option>
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($filterCurrency === $code)>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mr-3 mb-2">
                            <label for="fx-from" class="f-12 text-lightest">{{ $t('list.from') }}</label>
                            <input type="text" name="from" id="fx-from" class="form-control height-35 f-14" autocomplete="off"
                                   placeholder="{{ __('placeholders.date') }}" value="{{ $filterFrom }}" style="max-width: 140px">
                        </div>
                        <div class="mr-3 mb-2">
                            <label for="fx-to" class="f-12 text-lightest">{{ $t('list.to') }}</label>
                            <input type="text" name="to" id="fx-to" class="form-control height-35 f-14" autocomplete="off"
                                   placeholder="{{ __('placeholders.date') }}" value="{{ $filterTo }}" style="max-width: 140px">
                        </div>
                        <div class="mr-3 mb-2">
                            <label for="fx-filter-source" class="f-12 text-lightest">{{ $t('list.source') }}</label>
                            <select name="source" id="fx-filter-source" class="form-control height-35 f-14">
                                <option value="">{{ $t('list.all_sources') }}</option>
                                @foreach (\App\Models\Databackd\FinFxRate::SOURCES as $source)
                                    <option value="{{ $source }}" @selected($filterSource === $source)>{{ $t('sources.' . $source) }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="mb-2">
                            <button type="submit" class="btn btn-secondary rounded f-14 p-2 height-35 mr-2">
                                <i class="fa fa-filter mr-1"></i>{{ $t('list.apply') }}
                            </button>
                            @if ($filtered)
                                <a href="{{ route('databackd.gaps.fx-rates.index') }}" class="btn btn-outline-secondary rounded f-14 p-2 height-35">
                                    <i class="fa fa-times mr-1"></i>{{ $t('list.reset') }}
                                </a>
                            @endif
                        </div>
                    </form>

                    <div id="fx-list">
                        @if ($rates->isEmpty())
                            <div class="text-center py-5">
                                <i class="fa fa-exchange-alt f-36 text-lightest mb-3"></i>
                                <div class="f-15 text-dark mb-1">{{ $filtered ? $t('empty.filtered') : $t('empty.title') }}</div>
                                @if ($filtered)
                                    <a href="{{ route('databackd.gaps.fx-rates.index') }}" class="btn btn-secondary btn-sm mt-2">{{ $t('list.reset') }}</a>
                                @else
                                    <div class="f-13 text-lightest">{{ $t('empty.text') }}</div>
                                @endif
                            </div>
                        @else
                            <div class="table-responsive">
                                <x-table class="table-hover dbk-fx-table" id="fx-rates-table">
                                    <x-slot name="thead">
                                        <th class="pl-20">{{ $t('columns.date') }}</th>
                                        <th>{{ $t('columns.currency') }}</th>
                                        <th class="text-right">{{ $t('columns.rate') }}</th>
                                        <th>{{ $t('columns.source') }}</th>
                                        <th class="text-right pr-20" style="width: 110px">{{ $t('columns.actions') }}</th>
                                    </x-slot>

                                    @foreach ($rates as $row)
                                        <tr id="fx-row-{{ $row->id }}">
                                            <td class="pl-20 f-14 text-dark">{{ $row->rate_date->format(company()->date_format) }}</td>
                                            <td class="f-14">
                                                <span class="dbk-fx-code">{{ $row->currency }}</span>
                                                @if ($row->currency === 'BGN')
                                                    <span class="badge badge-light f-11 ml-1" title="{{ $t('list.bgn_unused_help') }}">{{ $t('list.bgn_unused') }}</span>
                                                @endif
                                            </td>
                                            <td class="dbk-fx-num f-14" title="{{ $t('list.rate_hint', ['rate' => $fmtRate($row->rate), 'currency' => $row->currency]) }}">{{ $fmtRate($row->rate) }}</td>
                                            <td>
                                                <span class="badge badge-{{ $row->source === 'manual' ? 'info' : 'secondary' }} f-12">{{ $t('sources.' . $row->source) }}</span>
                                            </td>
                                            <td class="text-right pr-20">
                                                <button type="button" class="btn btn-sm btn-outline-secondary fx-edit" data-id="{{ $row->id }}"
                                                        title="{{ $t('list.edit') }}" aria-label="{{ $t('list.edit') }}">
                                                    <i class="fa fa-edit" aria-hidden="true"></i>
                                                </button>
                                                <button type="button" class="btn btn-sm btn-outline-danger fx-delete ml-1" data-id="{{ $row->id }}"
                                                        data-label="{{ $row->currency }} · {{ $row->rate_date->format(company()->date_format) }}"
                                                        title="{{ $t('list.delete') }}" aria-label="{{ $t('list.delete') }}">
                                                    <i class="fa fa-trash" aria-hidden="true"></i>
                                                </button>
                                            </td>
                                        </tr>
                                    @endforeach
                                </x-table>
                            </div>

                            <div class="d-flex flex-wrap justify-content-between align-items-center pl-20 pr-20 py-3 border-top">
                                <span class="f-13 text-lightest mb-2 mb-md-0">
                                    {{ $t('list.showing', ['from' => $rates->firstItem(), 'to' => $rates->lastItem(), 'total' => number_format($rates->total(), 0, ',', ' ')]) }}
                                </span>
                                @if ($rates->hasPages())
                                    <div>{{ $rates->onEachSide(1)->links('pagination::bootstrap-4') }}</div>
                                @endif
                            </div>
                        @endif
                    </div>
                </x-cards.data>
            </div>

            {{-- Chart --}}
            <div class="col-xl-4">
                <x-cards.data :title="$t('chart.title')">
                    @if (empty($currencies))
                        <p class="f-14 text-lightest mb-0">{{ $t('chart.no_data') }}</p>
                    @else
                        <div class="mb-3">
                            <label for="fx-chart-currency" class="f-12 text-lightest d-block mb-1">{{ $t('chart.currency') }}</label>
                            <select id="fx-chart-currency" class="form-control height-35 f-14">
                                @foreach ($currencies as $code)
                                    <option value="{{ $code }}" @selected($chartCurrency === $code)>{{ $code }}</option>
                                @endforeach
                            </select>
                        </div>
                        <div class="dbk-fx-chart-box">
                            <canvas id="fx-chart" role="img" aria-label="{{ $t('chart.title') }}"></canvas>
                        </div>
                        <p class="f-12 text-lightest mt-2 mb-0" id="fx-chart-hint"></p>
                    @endif
                </x-cards.data>
                <p class="f-12 text-lightest mt-3 mb-0"><i class="fa fa-info-circle mr-1"></i>{{ $t('usage_note', ['days' => $maxAgeDays]) }}</p>
            </div>
        </div>
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var fxTexts = {
                confirmTitle: @json($t('delete.title')),
                confirmText: @json($t('delete.text', ['label' => ':label'])),
                confirmButton: @json($t('delete.confirm')),
                cancel: @json($t('delete.cancel')),
                noData: @json($t('chart.no_data')),
                seriesLabel: @json($t('chart.series', ['currency' => ':currency'])),
                points: @json($t('chart.points', ['count' => ':count']))
            };

            datepicker('#fx-from', {position: 'bl', ...datepickerConfig});
            datepicker('#fx-to', {position: 'bl', ...datepickerConfig});

            $('#fx-filter-currency, #fx-filter-source').on('change', function () {
                $('#fx-filter-form').submit();
            });

            // ------------------------------------------------------------ add / edit / delete
            $('#fx-add-rate').on('click', function () {
                $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
                $.ajaxModal(MODAL_LG, "{{ route('databackd.gaps.fx-rates.create') }}");
            });

            $('body').on('click', '.fx-edit', function () {
                var url = "{{ route('databackd.gaps.fx-rates.edit', ':id') }}".replace(':id', $(this).data('id'));
                $(MODAL_LG + ' ' + MODAL_HEADING).html('...');
                $.ajaxModal(MODAL_LG, url);
            });

            $('body').on('click', '.fx-delete', function () {
                var id = $(this).data('id');
                var label = $(this).data('label');

                Swal.fire({
                    title: fxTexts.confirmTitle,
                    text: fxTexts.confirmText.replace(':label', label),
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: fxTexts.confirmButton,
                    cancelButtonText: fxTexts.cancel,
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.easyAjax({
                        type: 'POST',
                        url: "{{ route('databackd.gaps.fx-rates.destroy', ':id') }}".replace(':id', id),
                        blockUI: true,
                        data: {'_token': '{{ csrf_token() }}', '_method': 'DELETE'},
                        success: function (response) {
                            if (response.status === 'success') {
                                window.location.reload();
                            }
                        }
                    });
                });
            });

            // ------------------------------------------------------------ ECB (only on click)
            $('#fx-fetch-ecb').on('click', function () {
                $.easyAjax({
                    type: 'POST',
                    url: "{{ route('databackd.gaps.fx-rates.fetch-ecb') }}",
                    container: '.content-wrapper',
                    blockUI: true,
                    disableButton: true,
                    buttonSelector: '#fx-fetch-ecb',
                    data: {'_token': '{{ csrf_token() }}', 'overwrite_manual': $('#fx-overwrite-manual').is(':checked') ? 1 : 0},
                    success: function (response) {
                        if (response.status === 'success') {
                            // the reloaded page shows the result box (session flash)
                            window.location.reload();
                        } else if (response.message) {
                            $('#fx-ecb-result').html($('<div class="alert alert-danger f-13 mb-0" role="alert"></div>').text(response.message));
                        }
                    }
                });
            });

            // ------------------------------------------------------------ chart
            var chartUrl = "{{ route('databackd.gaps.fx-rates.chart') }}";

            function loadChart(currency) {
                $.getJSON(chartUrl, {currency: currency, from: $('#fx-from').val(), to: $('#fx-to').val()}, function (r) {
                    if (!r.count) {
                        dbkChart.empty('#fx-chart', fxTexts.noData);
                        $('#fx-chart-hint').text('');
                        return;
                    }

                    dbkChart.line('#fx-chart', {
                        labels: r.labels,
                        datasets: [{label: fxTexts.seriesLabel.replace(':currency', r.currency), data: r.data, role: 'profit'}],
                        unit: function (v) { return dbkChart.fmt.number(v, 4); },
                        emptyText: fxTexts.noData
                    });
                    $('#fx-chart-hint').text(fxTexts.points.replace(':count', r.count));
                });
            }

            if ($('#fx-chart').length) {
                loadChart($('#fx-chart-currency').val());

                $('#fx-chart-currency').on('change', function () {
                    loadChart($(this).val());
                });
            }
        });
    </script>
@endpush

@extends('layouts.app')

@push('datatable-styles')
    @include('sections.datatable_css')
@endpush

@section('filter-section')

    <x-filters.filter-box>
        <!-- DATE START -->
        <div class="select-box d-flex pr-2 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('app.duration')</p>
            <div class="select-status d-flex">
                <input type="text" class="position-relative text-dark form-control border-0 p-2 text-left f-14 f-w-500 border-additional-grey"
                    id="datatableRange" placeholder="@lang('placeholders.dateRange')">
            </div>
        </div>
        <!-- DATE END -->

        <!-- SOURCE START -->
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-batches.filters.source')</p>
            <div class="select-status">
                <select class="form-control select-picker" id="filter-source">
                    <option value="all">@lang('databackd-data-batches.filters.all')</option>
                    @foreach ($sources as $source)
                        <option value="{{ $source }}">{{ __('databackd-data-common.sources.' . $source) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <!-- SOURCE END -->

        <!-- STATUS START -->
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-batches.filters.status')</p>
            <div class="select-status">
                <select class="form-control select-picker" id="filter-status">
                    <option value="all">@lang('databackd-data-batches.filters.all')</option>
                    @foreach ($statuses as $status)
                        <option value="{{ $status }}">{{ __('databackd-data-common.statuses.' . $status) }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <!-- STATUS END -->

        <!-- SEARCH START -->
        <div class="task-search d-flex py-1 px-lg-3 px-0 border-right-grey align-items-center">
            <form class="w-100 mr-1 mr-lg-0 mr-md-1 ml-md-1 ml-0 ml-lg-0">
                <div class="input-group bg-grey rounded">
                    <div class="input-group-prepend">
                        <span class="input-group-text border-0 bg-additional-grey">
                            <i class="fa fa-search f-13 text-dark-grey"></i>
                        </span>
                    </div>
                    <input type="text" class="form-control f-14 p-1 border-additional-grey" id="search-text-field"
                        placeholder="@lang('databackd-data-batches.filters.search')">
                </div>
            </form>
        </div>
        <!-- SEARCH END -->

        <!-- RESET START -->
        <div class="select-box d-flex py-1 px-lg-2 px-md-2 px-0">
            <x-forms.button-secondary class="btn-xs d-none" id="reset-filters" icon="times-circle">
                @lang('app.clearFilters')
            </x-forms.button-secondary>
        </div>
        <!-- RESET END -->
    </x-filters.filter-box>

@endsection

@section('content')
    <!-- CONTENT WRAPPER START -->
    <div class="content-wrapper dbk-data-page">

        <x-dbk.page-header :subtitle="__('databackd-data-batches.subtitle')" />

        <!-- STAT CARDS START -->
        @php
            $fmtInt = fn($n) => number_format((int)$n, 0, ',', ' ');
        @endphp
        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-data-batches.cards.total')" :value="$fmtInt($totalBatches)" icon="bi-clock-history" />
            <x-dbk.kpi :label="__('databackd-data-batches.cards.imported')" :value="$fmtInt($importedRows)" icon="bi-check-circle" tone="revenue" />
            <x-dbk.kpi :label="__('databackd-data-batches.cards.failed')" :value="$fmtInt($failedRows)" icon="bi-exclamation-triangle" :tone="$failedRows > 0 ? 'expense' : null" />
            <x-dbk.kpi :label="__('databackd-data-batches.cards.undone')" :value="$fmtInt($undoneBatches)" icon="bi-arrow-counterclockwise" />
        </div>
        <!-- STAT CARDS END -->

        @if ($totalBatches == 0)
            <div class="dbk-card">
                <x-dbk.empty icon="clock-history" :title="__('databackd-data-batches.empty.title')" :text="__('databackd-data-batches.empty.text')" />
            </div>
        @else
            <!-- Table Box Start -->
            <div class="d-flex flex-column w-tables rounded bg-white table-responsive dbk-data-dt dbk-data-batches-dt">
                {!! $dataTable->table(['class' => 'table table-hover border-0 w-100']) !!}
            </div>
            <!-- Table Box End -->
        @endif
    </div>
    <!-- CONTENT WRAPPER END -->

@endsection

@if ($totalBatches > 0)
    @push('scripts')
        @include('sections.datatable_js')

        <script>
            $('#batches-table').on('preXhr.dt', function(e, settings, data) {
                var dateRangePicker = $('#datatableRange').data('daterangepicker');
                var startDate = $('#datatableRange').val();
                var endDate = null;

                if (startDate == '') {
                    startDate = null;
                } else {
                    startDate = dateRangePicker.startDate.format('{{ company()->moment_date_format }}');
                    endDate = dateRangePicker.endDate.format('{{ company()->moment_date_format }}');
                }

                data['startDate'] = startDate;
                data['endDate'] = endDate;
                data['source'] = $('#filter-source').val();
                data['status'] = $('#filter-status').val();
                data['searchText'] = $('#search-text-field').val();
            });

            const showTable = () => {
                window.LaravelDataTables["batches-table"].draw(false);
            }

            const toggleReset = () => {
                var active = $('#filter-source').val() != 'all' || $('#filter-status').val() != 'all'
                    || $('#search-text-field').val() != '' || $('#datatableRange').val() != '';
                $('#reset-filters').toggleClass('d-none', !active);
            }

            $('#filter-source, #filter-status').on('change', function() {
                toggleReset();
                showTable();
            });

            $('#search-text-field').on('keyup', function() {
                toggleReset();
                showTable();
            });

            $('#datatableRange').on('apply.daterangepicker', function() {
                toggleReset();
                showTable();
            });

            $('#reset-filters').click(function() {
                $('#filter-form')[0].reset();
                $('#datatableRange').val('');
                $('.filter-box .select-picker').selectpicker('refresh');
                $('#reset-filters').addClass('d-none');
                showTable();
            });

        </script>
        @include('databackd.data.batches._undo_script', ['selector' => '.undo-batch'])
    @endpush
@endif

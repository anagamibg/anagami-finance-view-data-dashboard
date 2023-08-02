@extends('layouts.app')

@push('datatable-styles')
    @include('sections.datatable_css')
@endpush

@section('filter-section')

    <x-filters.filter-box>
        <!-- PERIOD START -->
        <div class="select-box d-flex pr-2 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-entries.filters.period')</p>
            <div class="select-status d-flex">
                <input type="text" class="position-relative text-dark form-control border-0 p-2 text-left f-14 f-w-500 border-additional-grey"
                    id="datatableRange" placeholder="@lang('placeholders.dateRange')">
            </div>
        </div>
        <!-- PERIOD END -->

        <!-- ENTITY START -->
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-entries.filters.entity')</p>
            <div class="select-status">
                <select class="form-control select-picker" id="filter-entity" data-size="8">
                    <option value="all">@lang('databackd-data-entries.filters.all')</option>
                    @foreach ($entities as $entity)
                        <option value="{{ $entity->id }}">{{ $entity->name }}</option>
                    @endforeach
                </select>
            </div>
        </div>
        <!-- ENTITY END -->

        <!-- ACCOUNT TYPE START -->
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-entries.filters.account_type')</p>
            <div class="select-status">
                <select class="form-control select-picker" id="filter-account-type" data-size="8">
                    <option value="all">@lang('databackd-data-entries.filters.all')</option>
                    @foreach ($accountTypes as $type)
                        <option value="{{ $type }}">@lang('databackd-data-common.account_types.' . $type)</option>
                    @endforeach
                </select>
            </div>
        </div>
        <!-- ACCOUNT TYPE END -->

        <!-- SOURCE START -->
        <div class="select-box d-flex py-2 px-lg-2 px-md-2 px-0 border-right-grey border-right-grey-sm-0">
            <p class="mb-0 pr-2 f-14 text-dark-grey d-flex align-items-center">@lang('databackd-data-entries.filters.source')</p>
            <div class="select-status">
                <select class="form-control select-picker" id="filter-source" data-size="8">
                    <option value="all">@lang('databackd-data-entries.filters.all')</option>
                    @foreach (\App\Models\Databackd\FinEntry::SOURCES as $source)
                        <option value="{{ $source }}">@lang('databackd-data-common.sources.' . $source)</option>
                    @endforeach
                </select>
            </div>
        </div>
        <!-- SOURCE END -->

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
                        placeholder="@lang('databackd-data-entries.filters.search_placeholder')">
                </div>
            </form>
        </div>
        <!-- SEARCH END -->

        <!-- RESET START -->
        <div class="select-box d-flex py-1 px-lg-2 px-md-2 px-0">
            <x-forms.button-secondary class="btn-xs d-none" id="reset-filters" icon="times-circle">
                @lang('databackd-data-entries.filters.clear')
            </x-forms.button-secondary>
        </div>
        <!-- RESET END -->

        <!-- MORE FILTERS START -->
        <x-filters.more-filter-box>
            <div class="more-filter-items">
                <label class="f-14 text-dark-grey mb-12">@lang('databackd-data-entries.filters.account')</label>
                <div class="select-filter mb-4">
                    <div class="select-others">
                        <select class="form-control select-picker" id="filter-account" data-container="body" data-live-search="true" data-size="8">
                            <option value="all">@lang('databackd-data-entries.filters.all')</option>
                            @foreach ($accounts as $account)
                                <option value="{{ $account->id }}">{{ $account->code }} · {{ $account->name }}</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            <div class="more-filter-items">
                <label class="f-14 text-dark-grey mb-12">@lang('databackd-data-entries.filters.kind')</label>
                <div class="select-filter mb-4">
                    <div class="select-others">
                        <select class="form-control select-picker" id="filter-kind" data-container="body">
                            <option value="all">@lang('databackd-data-entries.filters.all')</option>
                            @foreach (\App\Models\Databackd\FinEntry::KINDS as $kind)
                                <option value="{{ $kind }}">@lang('databackd-data-common.kinds.' . $kind)</option>
                            @endforeach
                        </select>
                    </div>
                </div>
            </div>

            @foreach (['region' => 'filter-region', 'client_segment' => 'filter-segment', 'product_group' => 'filter-product', 'cost_center' => 'filter-cost-center'] as $dimType => $filterId)
                <div class="more-filter-items">
                    <label class="f-14 text-dark-grey mb-12">@lang('databackd-data-entries.filters.' . $dimType)</label>
                    <div class="select-filter mb-4">
                        <div class="select-others">
                            <select class="form-control select-picker" id="{{ $filterId }}" data-container="body" data-live-search="true" data-size="8">
                                <option value="all">@lang('databackd-data-entries.filters.all')</option>
                                @foreach ($dimensions->get($dimType, collect()) as $dimension)
                                    <option value="{{ $dimension->id }}">{{ $dimension->name }}</option>
                                @endforeach
                            </select>
                        </div>
                    </div>
                </div>
            @endforeach
        </x-filters.more-filter-box>
        <!-- MORE FILTERS END -->

    </x-filters.filter-box>

@endsection

@section('content')
    <!-- CONTENT WRAPPER START -->
    <div class="content-wrapper dbk-data-page">

        <x-dbk.page-header :subtitle="__('databackd-data-entries.subtitle')">
            <x-slot name="actions">
                <div id="table-actions" class="dbk-data-actions">
                    <x-forms.link-primary :link="route('databackd.data.entries.create')" class="openRightModal" icon="plus">
                        @lang('databackd-data-entries.add_entry')
                    </x-forms.link-primary>

                    @if ($bulkUrl)
                        <x-forms.link-secondary :link="$bulkUrl" icon="table">
                            @lang('databackd-data-entries.bulk_entry')
                        </x-forms.link-secondary>
                    @endif

                    @if ($importUrl)
                        <x-forms.link-secondary :link="$importUrl" icon="file-upload">
                            @lang('databackd-data-entries.import_data')
                        </x-forms.link-secondary>
                    @endif

                    <x-forms.link-secondary :link="route('databackd.data.entries.export')" icon="file-excel" id="export-entries">
                        @lang('databackd-data-entries.export_excel')
                    </x-forms.link-secondary>
                </div>
            </x-slot>
        </x-dbk.page-header>

        @if ($urlEntityIds || $urlAccountIds)
            <div class="alert alert-info f-13 mb-3 d-flex align-items-center" id="url-filters">
                <i class="fa fa-filter mr-2"></i>
                <div class="mr-auto">
                    <span class="f-w-500">@lang('databackd-data-entries.url_filters.title')</span>
                    @if ($urlAccountIds)
                        <span class="ml-2">{{ __('databackd-data-entries.url_filters.accounts', ['list' => $urlAccountsText]) }}</span>
                    @endif
                    @if ($urlEntityIds)
                        <span class="ml-2">{{ __('databackd-data-entries.url_filters.entities', ['list' => $urlEntitiesText]) }}</span>
                    @endif
                </div>
                <button type="button" class="btn btn-link f-13 p-0 ml-3" id="url-filters-clear">
                    <i class="fa fa-times-circle mr-1"></i>@lang('databackd-data-entries.url_filters.clear')
                </button>
            </div>
        @endif

        @unless ($hasEntries)
            <div class="dbk-card mb-3">
                <x-dbk.empty icon="journal-text" :title="__('databackd-data-entries.empty.title')" :text="__('databackd-data-entries.empty.text')" size="sm">
                    <div class="dbk-empty-actions">
                        <x-forms.link-primary :link="route('databackd.data.entries.create')" class="openRightModal" icon="plus">
                            @lang('databackd-data-entries.add_entry')
                        </x-forms.link-primary>
                        @if ($importUrl)
                            <x-forms.link-secondary :link="$importUrl" icon="file-upload">
                                @lang('databackd-data-entries.import_data')
                            </x-forms.link-secondary>
                        @endif
                    </div>
                </x-dbk.empty>
            </div>
        @endunless

        <!-- Totals start -->
        <div class="dbk-kpi-grid fin-entries-totals">
            <div class="dbk-kpi">
                <div class="dbk-kpi-top">
                    <p class="dbk-kpi-label">@lang('databackd-data-entries.totals.result')</p>
                    <span class="dbk-kpi-icon" aria-hidden="true"><i class="bi bi-calculator"></i></span>
                </div>
                <div class="dbk-kpi-value" id="total-amount">--</div>
            </div>
            <div class="dbk-kpi">
                <div class="dbk-kpi-top">
                    <p class="dbk-kpi-label">@lang('databackd-data-entries.totals.flow')</p>
                    <span class="dbk-kpi-icon is-profit" aria-hidden="true"><i class="bi bi-arrow-left-right"></i></span>
                </div>
                <div class="dbk-kpi-value" id="total-flow">--</div>
            </div>
            <div class="dbk-kpi">
                <div class="dbk-kpi-top">
                    <p class="dbk-kpi-label">@lang('databackd-data-entries.totals.balance')</p>
                    <span class="dbk-kpi-icon is-cash" aria-hidden="true"><i class="bi bi-bank"></i></span>
                </div>
                <div class="dbk-kpi-value" id="total-balance">--</div>
            </div>
            <div class="dbk-kpi">
                <div class="dbk-kpi-top">
                    <p class="dbk-kpi-label">@lang('databackd-data-entries.totals.count')</p>
                    <span class="dbk-kpi-icon" aria-hidden="true"><i class="bi bi-list-ol"></i></span>
                </div>
                <div class="dbk-kpi-value" id="total-count">--</div>
            </div>
        </div>
        <p class="dbk-data-hint">@lang('databackd-data-entries.totals.hint')</p>
        <!-- Totals end -->

        <!-- Quick actions (shown when rows are selected) -->
        <div class="d-flex justify-content-end dbk-data-quick">
            <x-datatable.actions>
                <div class="select-status mr-3 pl-3">
                    <select name="action_type" class="form-control select-picker" id="quick-action-type" disabled>
                        <option value="">@lang('databackd-data-entries.actions.select_action')</option>
                        <option value="delete">@lang('databackd-data-entries.actions.delete')</option>
                    </select>
                </div>
            </x-datatable.actions>
        </div>

        <!-- Table start -->
        <div class="d-flex flex-column w-tables rounded bg-white table-responsive dbk-data-dt dbk-data-entries-dt">
            {!! $dataTable->table(['class' => 'table table-hover border-0 w-100']) !!}
        </div>
        <!-- Table end -->
    </div>
    <!-- CONTENT WRAPPER END -->
@endsection

@push('scripts')
    @include('sections.datatable_js')

    <script>
        const filterIds = {
            entityId: '#filter-entity',
            accountType: '#filter-account-type',
            source: '#filter-source',
            accountId: '#filter-account',
            kind: '#filter-kind',
            regionId: '#filter-region',
            clientSegmentId: '#filter-segment',
            productGroupId: '#filter-product',
            costCenterId: '#filter-cost-center'
        };

        const initialBatchId = '{{ $initialBatchId ?: '' }}';
        const initialAccountId = '{{ $initialAccountId ?: '' }}';
        const initialEntityId = '{{ $initialEntityId ?: '' }}';
        const dateFormat = '{{ company()->moment_date_format }}';

        // Links with a period / several ids (?from=Y-m-d&to=Y-m-d&entity_id=1,2&account_id=5,6): the selects are
        // prefilled here, the period and the id lists are kept in these variables, which the requests of the
        // table read (the date picker itself is created later, after the table has asked for its first page).
        let selectedRange = @json($initialFrom && $initialTo ? ['from' => $initialFrom, 'to' => $initialTo] : null);
        let extraIds = {entityIds: @json($urlEntityIds), accountIds: @json($urlAccountIds)};

        if (selectedRange) {
            selectedRange = {start: moment(selectedRange.from, 'YYYY-MM-DD'), end: moment(selectedRange.to, 'YYYY-MM-DD')};
        }

        if (initialAccountId) {
            $('#filter-account').val(initialAccountId).selectpicker('refresh');
        }

        if (initialEntityId) {
            $('#filter-entity').val(initialEntityId).selectpicker('refresh');
        }

        $('#datatableRange').on('apply.daterangepicker', function (event, picker) {
            selectedRange = {start: picker.startDate.clone(), end: picker.endDate.clone()};
        });

        const currentFilters = () => {
            var data = {};

            // always sent (empty = no period): the page controls win over the address of the page
            data['startDate'] = selectedRange ? selectedRange.start.format(dateFormat) : '';
            data['endDate'] = selectedRange ? selectedRange.end.format(dateFormat) : '';

            $.each(filterIds, function (key, selector) {
                data[key] = $(selector).val();
            });

            data['entityIds'] = extraIds.entityIds.join(',');
            data['accountIds'] = extraIds.accountIds.join(',');

            data['searchText'] = $('#search-text-field').val();

            // links from the import history / chart of accounts: ?batch_id=..
            if (initialBatchId) {
                data['batchId'] = initialBatchId;
            }

            return data;
        };

        $('#fin-entries-table').on('preXhr.dt', function (e, settings, data) {
            $.extend(data, currentFilters());
        });

        $('#fin-entries-table').on('xhr.dt', function (e, settings, json) {
            if (json && json.totals) {
                $('#total-amount').text(json.totals.total);
                $('#total-flow').text(json.totals.flow);
                $('#total-balance').text(json.totals.balance);
                $('#total-count').text(json.totals.count);
            }
        });

        const showTable = () => {
            window.LaravelDataTables["fin-entries-table"].draw(false);
        };

        const refreshResetButton = () => {
            var active = $('#datatableRange').val() !== '' || $('#search-text-field').val() !== ''
                || extraIds.entityIds.length > 0 || extraIds.accountIds.length > 0;

            $.each(filterIds, function (key, selector) {
                if ($(selector).val() !== 'all') {
                    active = true;
                }
            });

            $('#reset-filters').toggleClass('d-none', !active);
        };

        $(Object.values(filterIds).join(',')).on('change', function () {
            refreshResetButton();
            showTable();
        });

        // S12/A07: one request after the user stops typing, not one per key
        let searchTimer = null;

        $('#search-text-field').on('input', function () {
            refreshResetButton();
            clearTimeout(searchTimer);
            searchTimer = setTimeout(showTable, 350);
        }).closest('form').on('submit', function (event) {
            event.preventDefault();
            clearTimeout(searchTimer);
            showTable();
        });

        $('#reset-filters, #reset-filters-2').click(function () {
            $('#filter-form')[0].reset();
            $('#datatableRange').val('');
            selectedRange = null;
            extraIds = {entityIds: [], accountIds: []};
            $('#url-filters').remove();
            $(Object.values(filterIds).join(',')).selectpicker('val', 'all');
            $('#reset-filters').addClass('d-none');
            showTable();
        });

        // Remove the ids that came from the link (the banner above the table)
        $('#url-filters-clear').click(function () {
            extraIds = {entityIds: [], accountIds: []};
            $('#url-filters').remove();
            refreshResetButton();
            showTable();
        });

        // Period from the link: show it in the date range control once the picker exists
        $(function () {
            var picker = $('#datatableRange').data('daterangepicker');

            if (selectedRange) {
                if (picker) {
                    picker.setStartDate(selectedRange.start);
                    picker.setEndDate(selectedRange.end);
                }

                cb(selectedRange.start, selectedRange.end);
            }

            refreshResetButton();
        });

        // Excel export with the current filters
        $('#export-entries').click(function (event) {
            event.preventDefault();
            var params = $.param($.grep($.map(currentFilters(), function (value, key) {
                return {name: key, value: value};
            }), function (item) {
                return item.value !== 'all' && item.value !== '' && item.value !== null && typeof item.value !== 'undefined';
            }));

            window.location.href = "{{ route('databackd.data.entries.export') }}" + (params ? '?' + params : '');
        });

        const syncedSources = @json(\App\Services\Databackd\Data\InvoiceExpenseSync::SOURCES);

        const confirmDelete = (text, callback) => {
            Swal.fire({
                title: @json(__('databackd-data-entries.messages.confirm_title')),
                text: text,
                icon: 'warning',
                showCancelButton: true,
                focusConfirm: false,
                confirmButtonText: @json(__('databackd-data-entries.messages.confirm_button')),
                cancelButtonText: @json(__('databackd-data-entries.messages.cancel_button')),
                customClass: {
                    confirmButton: 'btn btn-primary mr-3',
                    cancelButton: 'btn btn-secondary'
                },
                showClass: {
                    popup: 'swal2-noanimation',
                    backdrop: 'swal2-noanimation'
                },
                buttonsStyling: false
            }).then((result) => {
                if (result.isConfirmed) {
                    callback();
                }
            });
        };

        $('body').on('click', '.delete-table-row', function () {
            var id = $(this).data('entry-id');
            // an entry transferred from an invoice / expense comes back with the next sync while its document exists
            var text = syncedSources.indexOf($(this).data('source')) !== -1
                ? @json(__('databackd-data-entries.messages.confirm_delete_synced'))
                : @json(__('databackd-data-entries.messages.confirm_delete'));

            confirmDelete(text, function () {
                var url = "{{ route('databackd.data.entries.destroy', ':id') }}".replace(':id', id);

                $.easyAjax({
                    type: 'POST',
                    url: url,
                    data: {
                        '_token': "{{ csrf_token() }}",
                        '_method': 'DELETE'
                    },
                    success: function (response) {
                        if (response.status == 'success') {
                            showTable();
                        }
                    }
                });
            });
        });

        $('#quick-action-type').change(function () {
            if ($(this).val() != '') {
                $('#quick-action-apply').removeAttr('disabled');
            } else {
                $('#quick-action-apply').attr('disabled', true);
            }
        });

        $('#quick-action-apply').click(function () {
            if ($('#quick-action-type').val() == 'delete') {
                var count = $("#fin-entries-table tbody input:checkbox:checked").length;
                confirmDelete(@json(__('databackd-data-entries.messages.confirm_delete_many')).replace(':count', count), applyQuickAction);
            }
        });

        const applyQuickAction = () => {
            var rowIds = $("#fin-entries-table input:checkbox:checked").map(function () {
                return $(this).val();
            }).get().filter(function (value) {
                return value !== 'on';
            });

            var url = "{{ route('databackd.data.entries.apply_quick_action') }}?row_ids=" + rowIds;

            $.easyAjax({
                url: url,
                container: '#quick-action-form',
                type: "POST",
                disableButton: true,
                buttonSelector: "#quick-action-apply",
                data: $('#quick-action-form').serialize(),
                success: function (response) {
                    if (response.status == 'success') {
                        showTable();
                        resetActionButtons();
                        deSelectAll();
                        $('#quick-action-form').hide();
                    }
                }
            });
        };
    </script>
@endpush

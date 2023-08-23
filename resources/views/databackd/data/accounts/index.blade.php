@extends('layouts.app')

@section('filter-section')
    <x-filters.filter-box>
        <div class="task-search d-flex py-1 px-lg-3 px-0 border-right-grey align-items-center">
            <div class="input-group bg-grey rounded">
                <div class="input-group-prepend">
                    <span class="input-group-text border-0 bg-additional-grey">
                        <i class="fa fa-search f-13 text-dark-grey"></i>
                    </span>
                </div>
                <input type="text" class="form-control f-14 p-1 border-additional-grey" id="coa-search"
                    autocomplete="off" placeholder="{{ __('databackd-data-accounts.search_placeholder') }}">
            </div>
        </div>
        <div class="select-box d-flex py-1 px-lg-2 px-md-2 px-0">
            <x-forms.button-secondary class="btn-xs mr-2" id="coa-expand-all" icon="angle-double-down">
                {{ __('databackd-data-accounts.expand_all') }}
            </x-forms.button-secondary>
            <x-forms.button-secondary class="btn-xs" id="coa-collapse-all" icon="angle-double-up">
                {{ __('databackd-data-accounts.collapse_all') }}
            </x-forms.button-secondary>
        </div>
    </x-filters.filter-box>
@endsection

@section('content')
    @php
        $typeBadges = ['asset' => 'is-info', 'liability' => 'is-warning', 'equity' => 'dbk-coa-equity', 'revenue' => 'is-good', 'expense' => 'is-bad'];
        $fmt = fn($n) => number_format($n, 0, ',', ' ');
    @endphp

    <div class="content-wrapper dbk-data-page dbk-coa">
        <x-dbk.page-header :subtitle="__('databackd-data-accounts.subtitle')">
            <x-slot name="actions">
                <div id="table-actions" class="dbk-data-actions">
                    <x-forms.link-primary :link="route('databackd.data.accounts.create')" class="openRightModal" icon="plus">
                        {{ __('databackd-data-accounts.add_account') }}
                    </x-forms.link-primary>
                    @if ($entriesRoute)
                        <x-forms.link-secondary :link="route($entriesRoute)" icon="list">
                            {{ __('databackd-data-accounts.financial_entries') }}
                        </x-forms.link-secondary>
                    @endif
                    @if ($chartTemplateUrl)
                        <x-forms.link-secondary :link="$chartTemplateUrl" icon="sitemap">
                            {{ __('databackd-data-accounts.chart_template') }}
                        </x-forms.link-secondary>
                    @endif
                </div>
            </x-slot>
        </x-dbk.page-header>

        <div class="dbk-kpi-grid">
            <x-dbk.kpi :label="__('databackd-data-accounts.summary.total')" :value="$fmt($totalAccounts)" icon="bi-diagram-3" />
            <x-dbk.kpi :label="__('databackd-data-accounts.summary.active')" :value="$fmt($activeAccounts)" icon="bi-check-circle" />
            <x-dbk.kpi :label="__('databackd-data-accounts.summary.with_entries')" :value="$fmt($accountsWithEntries)" icon="bi-arrow-left-right" />
            <x-dbk.kpi :label="__('databackd-data-accounts.summary.entries')" :value="$fmt($totalEntries)" icon="bi-list-ol" />
        </div>

        @forelse ($groups as $group)
            <div class="dbk-card coa-group" data-group="{{ $group['key'] }}">
                <div class="coa-group-header" role="button" tabindex="0"
                    data-toggle="collapse" data-target="#coa-group-{{ $group['key'] }}" aria-expanded="true" aria-controls="coa-group-{{ $group['key'] }}">
                    <i class="fa fa-chevron-down coa-chevron" aria-hidden="true"></i>
                    <span class="coa-group-digit">{{ $group['key'] === '0' ? '?' : $group['key'] }}</span>
                    <div class="coa-group-text">
                        <h3 class="coa-group-title">{{ __('databackd-data-accounts.groups.' . $group['key']) }}</h3>
                        <span class="coa-group-meta">
                            {{ __('databackd-data-accounts.accounts_count', ['count' => $group['accounts']]) }}
                            &middot; {{ __('databackd-data-accounts.summary.entries') }}: {{ $fmt($group['entries']) }}
                        </span>
                    </div>
                </div>

                <div class="collapse show" id="coa-group-{{ $group['key'] }}">
                    <div class="dbk-table-wrap is-flush">
                        <table class="dbk-table dbk-table--compact coa-table">
                            <thead>
                                <tr>
                                    <th class="coa-col-code">{{ __('databackd-data-accounts.columns.code') }}</th>
                                    <th>{{ __('databackd-data-accounts.columns.name') }}</th>
                                    <th>{{ __('databackd-data-accounts.columns.type') }}</th>
                                    <th>{{ __('databackd-data-accounts.columns.subtype') }}</th>
                                    <th class="num">{{ __('databackd-data-accounts.columns.entries') }}</th>
                                    <th>{{ __('databackd-data-accounts.columns.status') }}</th>
                                    <th class="text-right coa-col-actions">{{ __('databackd-data-accounts.columns.actions') }}</th>
                                </tr>
                            </thead>
                            <tbody>
                                @foreach ($group['rows'] as $row)
                                    @php
                                        $account = $row['account'];
                                        // review A: analytic accounts of 241 (2411, "A1" under 241) are contra too
                                        $isContra = $contraIds[$account->id] ?? in_array($account->code, $contraCodes, true);
                                        $isStandard = in_array($account->code, $standardCodes, true);
                                        // standard NSS accounts are deactivated, not deleted (the chart re-creates them)
                                        $canDelete = $row['entries'] === 0 && !$row['has_children'] && !$isStandard;
                                        $depth = min((int)$row['depth'], 6);
                                    @endphp
                                    <tr class="coa-row coa-depth-{{ $depth }} {{ $account->is_active ? '' : 'coa-inactive' }} {{ $row['has_children'] ? 'coa-parent' : '' }}"
                                        data-search="{{ mb_strtolower($account->code . ' ' . $account->name) }}">
                                        <td class="coa-code">{{ $account->code }}</td>
                                        <td>
                                            <div class="coa-name" style="--coa-depth: {{ $depth }}">
                                                <span class="coa-name-text">{{ $account->name }}</span>
                                                @if ($isStandard)
                                                    <span class="dbk-badge no-dot ml-1" data-toggle="tooltip"
                                                        data-original-title="{{ __('databackd-data-accounts.standard_note') }}">{{ __('databackd-data-accounts.standard_badge') }}</span>
                                                @endif
                                                @if ($isContra)
                                                    <span class="dbk-badge no-dot ml-1" data-toggle="tooltip"
                                                        data-original-title="{{ __('databackd-data-accounts.contra_note') }}">{{ __('databackd-data-accounts.contra_badge') }}</span>
                                                    <div class="coa-note">{{ __('databackd-data-accounts.contra_note') }}</div>
                                                @endif
                                            </div>
                                        </td>
                                        <td>
                                            <span class="dbk-badge {{ $typeBadges[$account->type] ?? '' }}">{{ __('databackd-data-common.account_types.' . $account->type) }}</span>
                                        </td>
                                        <td>
                                            @if ($account->subtype)
                                                <span class="dbk-chip">{{ __('databackd-data-accounts.subtypes.' . $account->subtype) }}</span>
                                            @else
                                                <span class="dbk-text-muted">-</span>
                                            @endif
                                        </td>
                                        <td class="num">
                                            @if ($row['entries'] > 0 && $entriesRoute)
                                                <a href="{{ route($entriesRoute, ['account_id' => $account->id]) }}"
                                                    title="{{ __('databackd-data-accounts.action_view_entries') }}">{{ $fmt($row['entries']) }}</a>
                                            @else
                                                <span class="{{ $row['entries'] > 0 ? '' : 'dbk-text-muted' }}">{{ $fmt($row['entries']) }}</span>
                                            @endif
                                        </td>
                                        <td class="text-nowrap">
                                            <span class="dbk-traffic {{ $account->is_active ? 'is-good' : 'is-bad' }} mr-1" aria-hidden="true"></span>
                                            {{ $account->is_active ? __('databackd-data-accounts.status_active') : __('databackd-data-accounts.status_inactive') }}
                                        </td>
                                        <td class="text-right coa-actions text-nowrap">
                                            <a href="{{ route('databackd.data.accounts.edit', $account->id) }}"
                                                class="btn btn-light border openRightModal" data-toggle="tooltip"
                                                data-original-title="{{ __('databackd-data-accounts.action_edit') }}" aria-label="{{ __('databackd-data-accounts.action_edit') }} {{ $account->code }}">
                                                <i class="fa fa-edit text-dark-grey"></i></a>
                                            <button type="button" class="btn btn-light border coa-toggle"
                                                data-id="{{ $account->id }}" data-toggle="tooltip"
                                                data-original-title="{{ $account->is_active ? __('databackd-data-accounts.action_deactivate') : __('databackd-data-accounts.action_activate') }}"
                                                aria-label="{{ $account->is_active ? __('databackd-data-accounts.action_deactivate') : __('databackd-data-accounts.action_activate') }} {{ $account->code }}">
                                                <i class="fa fa-toggle-{{ $account->is_active ? 'on text-success' : 'off text-dark-grey' }}"></i></button>
                                            @if ($canDelete)
                                                <button type="button" class="btn btn-light border coa-delete"
                                                    data-id="{{ $account->id }}" data-code="{{ $account->code }} {{ $account->name }}" data-toggle="tooltip"
                                                    data-original-title="{{ __('databackd-data-accounts.action_delete') }}" aria-label="{{ __('databackd-data-accounts.action_delete') }} {{ $account->code }}">
                                                    <i class="fa fa-trash text-danger"></i></button>
                                            @endif
                                        </td>
                                    </tr>
                                @endforeach
                            </tbody>
                        </table>
                    </div>
                </div>
            </div>
        @empty
            <div class="dbk-card">
                <x-dbk.empty icon="diagram-3" :title="__('databackd-data-accounts.empty_title')" :text="__('databackd-data-accounts.empty_text')" />
            </div>
        @endforelse

        <div id="coa-no-results" class="dbk-card mb-3" style="display:none">
            <x-dbk.empty icon="search" :title="__('databackd-data-accounts.no_results')" size="sm" />
        </div>

        @if (collect($contraCodes)->intersect(collect($groups)->flatMap(fn($g) => collect($g['rows'])->pluck('account.code'))->all())->isNotEmpty())
            <p class="dbk-data-hint"><i class="fa fa-info-circle mr-1"></i>{{ __('databackd-data-accounts.contra_legend') }}</p>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function() {
            var csrf = "{{ csrf_token() }}";

            // Group open / close
            $('#coa-expand-all').on('click', function() { $('.coa-group .collapse').collapse('show'); });
            $('#coa-collapse-all').on('click', function() { $('.coa-group .collapse').collapse('hide'); });

            $('.coa-group .collapse').on('show.bs.collapse hide.bs.collapse', function(e) {
                $(this).closest('.coa-group').find('.coa-group-header').toggleClass('collapsed', e.type === 'hide');
            });

            // Search by code / name
            $('#coa-search').on('input', function() {
                var q = $.trim($(this).val()).toLowerCase();
                var visibleGroups = 0;

                $('.coa-group').each(function() {
                    var $group = $(this), matches = 0;

                    $group.find('.coa-row').each(function() {
                        var show = q === '' || $(this).data('search').indexOf(q) !== -1;
                        $(this).toggle(show);
                        if (show) matches++;
                    });

                    $group.toggle(matches > 0);
                    if (matches > 0) {
                        visibleGroups++;
                        if (q !== '') $group.find('.collapse').collapse('show');
                    }
                });

                $('#coa-no-results').toggle(q !== '' && visibleGroups === 0);
            });

            $('#filter-form').on('submit', function(e) { e.preventDefault(); });

            // Activate / deactivate
            $('body').on('click', '.coa-toggle', function() {
                var url = "{{ route('databackd.data.accounts.toggle', ':id') }}".replace(':id', $(this).data('id'));

                $.easyAjax({
                    type: 'POST',
                    url: url,
                    blockUI: true,
                    data: {'_token': csrf},
                    success: function(response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });

            // Delete (only offered for accounts without entries)
            $('body').on('click', '.coa-delete', function() {
                var id = $(this).data('id');

                Swal.fire({
                    title: @json(__('databackd-data-accounts.messages.delete_title')),
                    text: @json(__('databackd-data-accounts.messages.delete_text')).replace(':account', $(this).data('code')),
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__('databackd-data-accounts.messages.delete_confirm')),
                    cancelButtonText: "@lang('app.cancel')",
                    customClass: {
                        confirmButton: 'btn btn-primary mr-3',
                        cancelButton: 'btn btn-secondary'
                    },
                    showClass: {
                        popup: 'swal2-noanimation',
                        backdrop: 'swal2-noanimation'
                    },
                    buttonsStyling: false
                }).then(function(result) {
                    if (result.isConfirmed) {
                        var url = "{{ route('databackd.data.accounts.destroy', ':id') }}".replace(':id', id);

                        $.easyAjax({
                            type: 'POST',
                            url: url,
                            blockUI: true,
                            data: {'_token': csrf, '_method': 'DELETE'},
                            success: function(response) {
                                if (response.status === 'success') {
                                    window.location.reload();
                                }
                            }
                        });
                    }
                });
            });
        });
    </script>
@endpush

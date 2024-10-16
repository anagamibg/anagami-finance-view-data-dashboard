@extends('layouts.app')

@section('content')
    @php
        $fmt = fn($v) => number_format((float)$v, 2, ',', ' ');
        $inv = $preview['invoices'];
        $exp = $preview['expenses'];
        // new, changed or no longer qualifying documents - anything run() would write
        $hasNew = ($inv['new'] + $exp['new'] + $inv['changed'] + $exp['changed'] + $inv['stale'] + $exp['stale']) > 0;
        $currency = $lastResult['currency'] ?? $preview['currency'];
        $hasAny = ($inv['candidates'] + $exp['candidates']) > 0;
    @endphp

    <div class="content-wrapper dbk-data-page">
        <div class="dbk-grid mb-4">
            <div class="dbk-span-8">
                <x-dbk.card :title="__('databackd-data-sync.intro_title')" :subtitle="__('databackd-data-sync.intro')" class="h-100">
                    <ul class="dbk-data-howto is-bullets sync-rules">
                        <li>@lang('databackd-data-sync.rule_invoices')</li>
                        <li>@lang('databackd-data-sync.rule_expenses')</li>
                        <li>@lang('databackd-data-sync.rule_marker')</li>
                    </ul>
                    <div class="alert alert-warning f-13 mb-0">
                        <i class="fa fa-exclamation-triangle mr-1"></i> @lang('databackd-data-sync.warning')
                    </div>
                </x-dbk.card>
            </div>

            <div class="dbk-span-4">
                <x-dbk.card :title="__('databackd-data-sync.run')" class="h-100">
                    <form id="sync-form" method="POST" action="{{ route('databackd.data.sync.run') }}">
                        @csrf
                        <x-forms.select fieldId="entity_id" :fieldLabel="__('databackd-data-sync.entity')" fieldName="entity_id" search="true">
                            @foreach ($entities as $entity)
                                <option value="{{ $entity->id }}" @selected($entity->id == $defaultEntityId)>{{ $entity->name }}@if($entity->eik) ({{ $entity->eik }})@endif</option>
                            @endforeach
                        </x-forms.select>
                        <p class="dbk-form-hint mb-3">@lang('databackd-data-sync.entity_hint')</p>

                        <div class="dbk-data-form-actions">
                        <x-forms.button-primary id="sync-run" icon="exchange-alt" :disabled="!$hasNew">
                            @lang('databackd-data-sync.run')
                        </x-forms.button-primary>
                        <a href="{{ route('databackd.data.sync.index') }}" class="btn btn-secondary rounded f-14 p-2">
                            <i class="fa fa-sync-alt mr-1"></i> @lang('databackd-data-sync.refresh')
                        </a>
                        </div>
                    </form>
                    @if (!$hasNew && $hasAny)
                        <p class="f-13 dbk-text-good mt-3 mb-0"><i class="fa fa-check-circle mr-1"></i> @lang('databackd-data-sync.nothing_new')</p>
                    @endif
                </x-dbk.card>
            </div>
        </div>

        {{-- Last run --}}
        <div class="mb-4">
            <div>
                <x-dbk.card :title="__('databackd-data-sync.last_result')">
                    @if ($lastResult)
                        <div class="dbk-kpi-grid mb-0">
                            <div class="dbk-kpi">
                                <div class="dbk-kpi-top"><p class="dbk-kpi-label">@lang('databackd-data-sync.invoices')</p></div>
                                <div class="dbk-kpi-value">{{ $lastResult['invoices_created'] }}</div>
                                <div class="dbk-kpi-foot"><span>@lang('databackd-data-sync.updated'): {{ $lastResult['invoices_updated'] ?? 0 }} · @lang('databackd-data-sync.removed'): {{ $lastResult['invoices_removed'] ?? 0 }} · @lang('databackd-data-sync.skipped'): {{ $lastResult['invoices_skipped'] }}</span></div>
                            </div>
                            <div class="dbk-kpi">
                                <div class="dbk-kpi-top"><p class="dbk-kpi-label">@lang('databackd-data-sync.expenses')</p></div>
                                <div class="dbk-kpi-value">{{ $lastResult['expenses_created'] }}</div>
                                <div class="dbk-kpi-foot"><span>@lang('databackd-data-sync.updated'): {{ $lastResult['expenses_updated'] ?? 0 }} · @lang('databackd-data-sync.removed'): {{ $lastResult['expenses_removed'] ?? 0 }} · @lang('databackd-data-sync.skipped'): {{ $lastResult['expenses_skipped'] }}</span></div>
                            </div>
                            <div class="dbk-kpi">
                                <div class="dbk-kpi-top"><p class="dbk-kpi-label">@lang('databackd-data-sync.revenue_total')</p></div>
                                <div class="dbk-kpi-value">{{ $fmt($lastResult['revenue_total']) }} {{ $currency }}</div>
                                <div class="dbk-kpi-foot"><span>{{ $lastResult['entity_name'] ?? '' }}</span></div>
                            </div>
                            <div class="dbk-kpi">
                                <div class="dbk-kpi-top"><p class="dbk-kpi-label">@lang('databackd-data-sync.expense_total')</p></div>
                                <div class="dbk-kpi-value">{{ $fmt($lastResult['expense_total']) }} {{ $currency }}</div>
                                <div class="dbk-kpi-foot"><span>@lang('databackd-data-sync.run_at'): {{ $lastResult['run_at'] ?? '' }}</span></div>
                            </div>
                        </div>
                        @if ($lastResult['invoices_created'] + $lastResult['expenses_created'] + ($lastResult['invoices_updated'] ?? 0) + ($lastResult['expenses_updated'] ?? 0) + ($lastResult['invoices_removed'] ?? 0) + ($lastResult['expenses_removed'] ?? 0) == 0)
                            <p class="f-13 text-dark-grey mt-3 mb-0">@lang('databackd-data-sync.nothing_new')</p>
                        @endif
                        @if (Route::has('databackd.data.entries.index'))
                            <a href="{{ route('databackd.data.entries.index') }}" class="btn btn-secondary rounded f-14 p-2 mt-3">
                                <i class="fa fa-list mr-1"></i> @lang('databackd-data-sync.view_entries')
                            </a>
                        @endif
                    @else
                        <x-dbk.empty icon="arrow-left-right" :text="__('databackd-data-sync.no_result')" size="sm" />
                    @endif
                </x-dbk.card>
            </div>
        </div>

        {{-- Dry-run preview --}}
        <div class="mb-4">
            <div>
                <x-dbk.card :title="__('databackd-data-sync.preview_title')" :subtitle="__('databackd-data-sync.preview_hint')">
                    @if ($hasAny || $hasNew)
                        <div class="dbk-table-wrap mb-4">
                            <table class="dbk-table">
                                <thead>
                                    <tr>
                                        <th>@lang('databackd-data-sync.documents')</th>
                                        <th class="num">@lang('databackd-data-sync.candidates')</th>
                                        <th class="num">@lang('databackd-data-sync.new')</th>
                                        <th class="num">@lang('databackd-data-sync.already')</th>
                                        <th class="num">@lang('databackd-data-sync.changed')</th>
                                        <th class="num">@lang('databackd-data-sync.stale')</th>
                                        <th class="num">@lang('databackd-data-sync.ignored')</th>
                                        <th class="num">@lang('databackd-data-sync.amount_new') ({{ $preview['currency'] }})</th>
                                        <th class="num">@lang('databackd-data-sync.amount_total') ({{ $preview['currency'] }})</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @foreach (['invoices' => $inv, 'expenses' => $exp] as $key => $row)
                                        <tr>
                                            <td>@lang('databackd-data-sync.' . $key)</td>
                                            <td class="num">{{ $row['candidates'] }}</td>
                                            <td class="num"><span class="dbk-badge no-dot {{ $row['new'] > 0 ? 'is-info' : '' }}">{{ $row['new'] }}</span></td>
                                            <td class="num">{{ $row['already'] }}</td>
                                            <td class="num">{{ $row['changed'] }}</td>
                                            <td class="num">{{ $row['stale'] }}</td>
                                            <td class="num">{{ $row['ignored'] }}</td>
                                            <td class="num">{{ $fmt($row['new_total']) }}</td>
                                            <td class="num">{{ $fmt($row['total']) }}</td>
                                        </tr>
                                    @endforeach
                                </tbody>
                            </table>
                        </div>

                        <div class="dbk-grid dbk-sync-tables">
                            <div class="dbk-span-6">
                                <h4 class="dbk-data-subhead">@lang('databackd-data-sync.accounts_title')</h4>
                                <div class="dbk-table-wrap">
                                    <table class="dbk-table dbk-table--compact">
                                        <thead>
                                            <tr>
                                                <th>@lang('databackd-data-sync.account')</th>
                                                <th>@lang('databackd-data-sync.source')</th>
                                                <th class="num">@lang('databackd-data-sync.count_new')</th>
                                                <th class="num">@lang('databackd-data-sync.already')</th>
                                                <th class="num">@lang('databackd-data-sync.amount_new')</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($preview['accounts'] as $acc)
                                                <tr>
                                                    <td>{{ $acc['code'] }} - {{ $acc['name'] }}</td>
                                                    <td>@lang('databackd-data-common.sources.' . $acc['source'])</td>
                                                    <td class="num">{{ $acc['new'] }}</td>
                                                    <td class="num">{{ $acc['already'] }}</td>
                                                    <td class="num">{{ $fmt($acc['new_total']) }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                            <div class="dbk-span-6">
                                <h4 class="dbk-data-subhead">@lang('databackd-data-sync.categories_title')</h4>
                                <div class="dbk-table-wrap">
                                    <table class="dbk-table dbk-table--compact">
                                        <thead>
                                            <tr>
                                                <th>@lang('databackd-data-sync.category')</th>
                                                <th>@lang('databackd-data-sync.account')</th>
                                                <th class="num">@lang('databackd-data-sync.new')</th>
                                                <th class="num">@lang('databackd-data-sync.already')</th>
                                            </tr>
                                        </thead>
                                        <tbody>
                                            @foreach ($preview['categories'] as $cat)
                                                <tr>
                                                    <td>{{ $cat['category'] }}</td>
                                                    <td>{{ $cat['account_code'] }} - {{ $cat['account_name'] }}</td>
                                                    <td class="num">{{ $cat['new'] }}</td>
                                                    <td class="num">{{ $cat['already'] }}</td>
                                                </tr>
                                            @endforeach
                                        </tbody>
                                    </table>
                                </div>
                            </div>
                        </div>
                    @else
                        <x-dbk.empty icon="inbox" :text="__('databackd-data-sync.no_data')" size="sm" />
                    @endif
                </x-dbk.card>
            </div>
        </div>

        @if (Route::has('databackd.data.integrations.index') && databackd_module('databackd_integrations')) {{-- audit 2026-10-30 (G11) --}}
            <a href="{{ route('databackd.data.integrations.index') }}" class="f-14 dbk-text-2">
                <i class="fa fa-arrow-left mr-1"></i> @lang('databackd-data-sync.integrations')
            </a>
        @endif
    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            $('.select-picker').selectpicker();

            $('#sync-run').on('click', function () {
                if ($(this).prop('disabled')) {
                    return;
                }
                Swal.fire({
                    title: @json(__('databackd-data-sync.confirm_title')),
                    text: @json(__('databackd-data-sync.confirm_text')),
                    icon: 'question',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__('databackd-data-sync.confirm_yes')),
                    cancelButtonText: @json(__('app.cancel')),
                    customClass: {confirmButton: 'btn btn-primary mr-3', cancelButton: 'btn btn-secondary'},
                    showClass: {popup: 'swal2-noanimation', backdrop: 'swal2-noanimation'},
                    buttonsStyling: false
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }
                    $.easyAjax({
                        url: "{{ route('databackd.data.sync.run') }}",
                        type: 'POST',
                        container: '#sync-form',
                        blockUI: true,
                        disableButton: true,
                        buttonSelector: '#sync-run',
                        data: $('#sync-form').serialize(),
                        success: function (response) {
                            if (response.status === 'success') {
                                window.location.reload();
                            }
                        }
                    });
                });
            });
        });
    </script>
@endpush

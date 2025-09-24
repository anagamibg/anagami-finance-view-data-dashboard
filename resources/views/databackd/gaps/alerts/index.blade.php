@extends('layouts.app')

@push('styles')
    <style>
        .al-table th { font-size: 12px; font-weight: 500; color: #66717f; border-top: 0; white-space: nowrap; }
        .al-table td { vertical-align: middle; }
        .al-inactive td { opacity: .55; }
        .al-inactive td.al-actions-cell { opacity: 1; }
        .al-name { color: #1d6fd6; font-weight: 500; }
        .al-condition { font-size: 13px; color: #4f5b6b; max-width: 460px; }
        .al-meta { font-size: 12px; color: #66717f; }
        .al-actions .btn { padding: 2px 8px; }
        .al-tabs .nav-link { color: #4f5b6b; }
        .al-tabs .nav-link.active { color: #1d2633; font-weight: 500; }
    </style>
@endpush

@section('content')
    @php
        $severityClass = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'];
        $typeIcons = ['ratio_range' => 'percentage', 'budget_deviation' => 'balance-scale', 'cash_below' => 'wallet', 'receivables_above' => 'file-invoice-dollar'];
    @endphp

    <div class="content-wrapper">

        <p class="f-14 text-dark-grey mb-3">@lang('databackd-gaps-alerts.subtitle')</p>

        @include('databackd.gaps.alerts._tabs')

        <div class="row mb-3">
            <div class="col-md-3 col-sm-6 mb-2">
                <x-cards.widget :title="__('databackd-gaps-alerts.summary.active')" :value="$activeCount . ' / ' . $rules->count()" icon="sliders-h" />
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <x-cards.widget :title="__('databackd-gaps-alerts.summary.triggered')" :value="$triggeredCount" icon="exclamation-triangle" />
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <x-cards.widget :title="__('databackd-gaps-alerts.summary.open')" :value="$openCount" icon="bell" />
            </div>
            <div class="col-md-3 col-sm-6 mb-2">
                <x-cards.widget :title="__('databackd-gaps-alerts.summary.last_check')"
                    :value="$lastChecked ? $lastChecked->copy()->timezone(company()->timezone)->format('d.m.Y H:i') : __('databackd-gaps-alerts.states.never')"
                    icon="clock" />
            </div>
        </div>

        @if ($canManage)
            <div class="d-flex flex-wrap align-items-center action-bar mb-3">
                <x-forms.link-primary :link="route('databackd.gaps.alerts.create')" class="mr-3 mb-2" icon="plus">
                    @lang('databackd-gaps-alerts.buttons.add')
                </x-forms.link-primary>

                <form method="POST" action="{{ route('databackd.gaps.alerts.check') }}" class="d-flex flex-wrap mb-2" id="al-check-form">
                    @csrf
                    <button type="submit" class="btn btn-secondary rounded f-14 p-2 mr-3" id="al-check-now"
                            data-toggle="tooltip" data-original-title="{{ __('databackd-gaps-alerts.buttons.check_now_help') }}">
                        <i class="fa fa-bolt mr-1"></i>@lang('databackd-gaps-alerts.buttons.check_now')
                    </button>
                    <button type="submit" name="dry_run" value="1" class="btn btn-outline-secondary rounded f-14 p-2" id="al-check-dry"
                            data-toggle="tooltip" data-original-title="{{ __('databackd-gaps-alerts.buttons.dry_run_help') }}">
                        <i class="fa fa-search mr-1"></i>@lang('databackd-gaps-alerts.buttons.dry_run')
                    </button>
                </form>
            </div>
        @endif

        @if ($checkResult)
            @include('databackd.gaps.alerts._check-result', ['summary' => $checkResult])
        @endif

        @if ($rules->isEmpty())
            <div class="bg-white rounded b-shadow-4 p-5 text-center">
                <i class="fa fa-bell f-36 text-lightest mb-3"></i>
                <h5 class="f-18 f-w-500 text-dark">@lang('databackd-gaps-alerts.empty.title')</h5>
                <p class="f-14 text-dark-grey mb-3 mx-auto" style="max-width: 560px;">@lang('databackd-gaps-alerts.empty.text')</p>
                @if ($canManage)
                    <x-forms.link-primary :link="route('databackd.gaps.alerts.create')" icon="plus">
                        @lang('databackd-gaps-alerts.buttons.add')
                    </x-forms.link-primary>
                @endif
            </div>
        @else
            <div class="card bg-white border-0 b-shadow-4 mb-3">
                <div class="table-responsive">
                    <table class="table table-hover mb-0 al-table" id="al-rules-table">
                        <thead>
                            <tr>
                                <th class="pl-20">@lang('databackd-gaps-alerts.table.rule')</th>
                                <th>@lang('databackd-gaps-alerts.table.severity')</th>
                                <th>@lang('databackd-gaps-alerts.table.channels')</th>
                                <th>@lang('databackd-gaps-alerts.table.state')</th>
                                <th>@lang('databackd-gaps-alerts.table.last_check')</th>
                                @if ($canManage)
                                    <th class="text-center">@lang('databackd-gaps-alerts.table.active')</th>
                                    <th class="text-right pr-20">@lang('databackd-gaps-alerts.table.actions')</th>
                                @endif
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($rules as $rule)
                                @php
                                    $budget = $rule->type === 'budget_deviation' ? ($budgets[$rule->params['budget_id'] ?? 0] ?? null) : null;
                                    $scope = $rule->type === 'budget_deviation'
                                        ? ($budget ? ($budget->entity->name ?? __('databackd-gaps-alerts.scope.group')) : '—')
                                        : ($rule->entity->name ?? __('databackd-gaps-alerts.scope.all'));
                                    $recipientCount = count((array)$rule->recipients);
                                    $condition = \App\Services\Databackd\Gaps\Alerts\AlertText::condition($rule, $ratios, $budgets, $currency);

                                    if (!$rule->is_active) {
                                        $state = 'inactive';
                                    } elseif (!$rule->last_checked_at) {
                                        $state = 'never';
                                    } else {
                                        $state = $rule->last_state;
                                    }

                                    $stateClass = ['inactive' => 'secondary', 'never' => 'light', 'ok' => 'success', 'triggered' => 'danger'][$state] ?? 'secondary';
                                @endphp
                                <tr id="al-row-{{ $rule->id }}" class="{{ $rule->is_active ? '' : 'al-inactive' }}">
                                    <td class="pl-20">
                                        @if ($canManage)
                                            <a href="{{ route('databackd.gaps.alerts.edit', $rule->id) }}" class="al-name f-14">{{ $rule->name }}</a>
                                        @else
                                            <span class="f-14 f-w-500 text-dark">{{ $rule->name }}</span>
                                        @endif
                                        <div class="al-condition text-truncate" title="{{ $condition }}">{{ $condition }}</div>
                                        <div class="al-meta">
                                            <i class="fa fa-{{ $typeIcons[$rule->type] ?? 'bell' }} mr-1"></i>@lang('databackd-gaps-alerts.types.' . $rule->type)
                                            <span class="mx-1">·</span>{{ $scope }}
                                        </div>
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $severityClass[$rule->severity] ?? 'secondary' }}">@lang('databackd-gaps-alerts.severities.' . $rule->severity)</span>
                                    </td>
                                    <td class="f-14 text-nowrap">
                                        @if ($rule->notify_app)
                                            <i class="fa fa-bell text-primary mr-2" data-toggle="tooltip" data-original-title="{{ __('databackd-gaps-alerts.channels.app') }}"></i>
                                        @endif
                                        @if ($rule->notify_email)
                                            <i class="fa fa-envelope text-primary" data-toggle="tooltip"
                                               data-original-title="{{ __('databackd-gaps-alerts.channels.email') }}{{ $recipientCount ? ' (+' . $recipientCount . ')' : '' }}"></i>
                                        @endif
                                        @if (!$rule->notify_app && !$rule->notify_email)
                                            <span class="text-lightest f-12">@lang('databackd-gaps-alerts.channels.history_only')</span>
                                        @endif
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $stateClass }}">@lang('databackd-gaps-alerts.states.' . $state)</span>
                                    </td>
                                    <td class="f-13 text-dark-grey text-nowrap">
                                        @if ($rule->last_checked_at)
                                            {{ $rule->last_checked_at->copy()->timezone(company()->timezone)->format('d.m.Y H:i') }}
                                        @else
                                            <span class="text-lightest">--</span>
                                        @endif
                                    </td>
                                    @if ($canManage)
                                        <td class="text-center al-actions-cell">
                                            <button type="button" class="btn btn-light border al-toggle" data-id="{{ $rule->id }}" data-toggle="tooltip"
                                                    data-original-title="{{ $rule->is_active ? __('databackd-gaps-alerts.buttons.deactivate') : __('databackd-gaps-alerts.buttons.activate') }}">
                                                <i class="fa fa-toggle-{{ $rule->is_active ? 'on text-success' : 'off text-dark-grey' }}"></i>
                                            </button>
                                        </td>
                                        <td class="text-right text-nowrap pr-20 al-actions al-actions-cell">
                                            <a href="{{ route('databackd.gaps.alerts.edit', $rule->id) }}" class="btn btn-light border" data-toggle="tooltip"
                                               data-original-title="{{ __('databackd-gaps-alerts.buttons.edit') }}"><i class="fa fa-edit"></i></a>
                                            <button type="button" class="btn btn-light border text-danger al-delete" data-id="{{ $rule->id }}" data-toggle="tooltip"
                                                    data-original-title="{{ __('databackd-gaps-alerts.buttons.delete') }}"><i class="fa fa-trash"></i></button>
                                        </td>
                                    @endif
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        <p class="f-12 text-lightest"><i class="fa fa-info-circle mr-1"></i>@lang('databackd-gaps-alerts.schedule_note')</p>

    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var csrf = "{{ csrf_token() }}";
            var urls = {
                toggle: "{{ route('databackd.gaps.alerts.toggle', ':id') }}",
                destroy: "{{ route('databackd.gaps.alerts.destroy', ':id') }}"
            };

            // The check may take a few seconds: block double clicks (after the form data is built)
            $('#al-check-form').on('submit', function () {
                window.setTimeout(function () {
                    $('#al-check-now, #al-check-dry').prop('disabled', true).css('opacity', .7);
                }, 20);
            });

            $('body').on('click', '.al-toggle', function () {
                $.easyAjax({
                    type: 'POST',
                    url: urls.toggle.replace(':id', $(this).data('id')),
                    blockUI: true,
                    data: {'_token': csrf},
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });

            $('body').on('click', '.al-delete', function () {
                var id = $(this).data('id');

                Swal.fire({
                    title: @json(__('databackd-gaps-alerts.messages.delete_title')),
                    text: @json(__('databackd-gaps-alerts.messages.delete_text')),
                    icon: 'warning',
                    showCancelButton: true,
                    focusConfirm: false,
                    confirmButtonText: @json(__('databackd-gaps-alerts.messages.delete_confirm')),
                    cancelButtonText: @json(__('app.cancel')),
                    customClass: {
                        confirmButton: 'btn btn-primary mr-3',
                        cancelButton: 'btn btn-secondary'
                    },
                    showClass: {
                        popup: 'swal2-noanimation',
                        backdrop: 'swal2-noanimation'
                    },
                    buttonsStyling: false
                }).then(function (result) {
                    if (!result.isConfirmed) {
                        return;
                    }

                    $.easyAjax({
                        type: 'POST',
                        url: urls.destroy.replace(':id', id),
                        blockUI: true,
                        data: {'_token': csrf, '_method': 'DELETE'},
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

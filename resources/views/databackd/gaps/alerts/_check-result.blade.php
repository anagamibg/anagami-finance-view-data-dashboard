{{-- AFV (B4): result of "Check now" (the summary returned by AlertChecker::checkCompany) --}}
@php
    $stateClass = ['triggered' => 'danger', 'ok' => 'success', 'no_data' => 'secondary', 'error' => 'dark'];
    $actionClass = ['fired' => 'danger', 'refired' => 'danger', 'unchanged' => 'secondary', 'recovered' => 'success', 'none' => 'light'];
@endphp
<div class="card bg-white border-0 b-shadow-4 mb-3 al-result">
    <div class="card-header bg-white border-bottom-grey py-3 d-flex align-items-center flex-wrap">
        <h5 class="mb-0 f-16 text-darkest-grey mr-3">
            <i class="fa fa-{{ $summary['dry_run'] ? 'search' : 'bolt' }} mr-1 text-primary"></i>
            {{ $summary['dry_run'] ? __('databackd-gaps-alerts.check.title_dry_run') : __('databackd-gaps-alerts.check.title') }}
        </h5>
        <span class="f-13 text-dark-grey">
            @lang('databackd-gaps-alerts.check.summary', [
                'checked' => $summary['checked'], 'triggered' => $summary['triggered'],
                'fired' => $summary['fired'], 'recovered' => $summary['recovered'],
            ])
        </span>
    </div>

    @if ($summary['dry_run'])
        <div class="alert alert-info f-13 mb-0 rounded-0 border-0"><i class="fa fa-info-circle mr-1"></i>@lang('databackd-gaps-alerts.check.dry_run_note')</div>
    @endif

    @if ($summary['checked'] === 0)
        <div class="card-body text-center text-lightest f-14">@lang('databackd-gaps-alerts.check.no_rules')</div>
    @else
        <div class="table-responsive">
            <table class="table table-hover mb-0 al-table">
                <thead>
                    <tr>
                        <th>@lang('databackd-gaps-alerts.table.rule')</th>
                        <th>@lang('databackd-gaps-alerts.table.state')</th>
                        <th class="text-right">@lang('databackd-gaps-alerts.table.value')</th>
                        <th class="text-right">@lang('databackd-gaps-alerts.table.threshold')</th>
                        <th>@lang('databackd-gaps-alerts.check.result')</th>
                    </tr>
                </thead>
                <tbody>
                    @foreach ($summary['results'] as $r)
                        <tr>
                            <td class="f-14">
                                <span class="f-w-500 text-dark">{{ $r['name'] }}</span>
                                <div class="f-12 text-lightest">@lang('databackd-gaps-alerts.types.' . $r['type'])</div>
                            </td>
                            <td>
                                <span class="badge badge-{{ $stateClass[$r['status']] ?? 'secondary' }}">@lang('databackd-gaps-alerts.states.' . $r['status'])</span>
                            </td>
                            <td class="text-right f-14 text-nowrap">{{ $r['value_text'] }}</td>
                            <td class="text-right f-14 text-nowrap">{{ $r['threshold_text'] }}</td>
                            <td class="f-13">
                                @if ($r['status'] === 'triggered' || $r['action'] === 'recovered')
                                    <span class="badge badge-{{ $actionClass[$r['action']] ?? 'secondary' }} mr-1">@lang('databackd-gaps-alerts.actions.' . $r['action'])</span>
                                    <span>{{ $r['message'] }}</span>
                                    @if ($r['event_id'])
                                        <span class="ml-1 text-lightest" title="@lang('databackd-gaps-alerts.table.channels')">
                                            @if ($r['notified_app'])<i class="fa fa-bell mr-1"></i>@endif
                                            @if ($r['notified_email'])<i class="fa fa-envelope"></i>@endif
                                        </span>
                                    @endif
                                @elseif ($r['status'] === 'ok')
                                    <span class="text-dark-grey">@lang('databackd-gaps-alerts.check.within_norm')</span>
                                @elseif ($r['status'] === 'no_data')
                                    <span class="text-dark-grey">{{ __('databackd-gaps-alerts.reasons.' . ($r['reason'] ?: 'no_entries')) }}</span>
                                @else
                                    <span class="text-danger">{{ $r['error'] ?: __('databackd-gaps-alerts.reasons.' . ($r['reason'] ?: 'exception')) }}</span>
                                @endif
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>

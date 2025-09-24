@extends('layouts.app')

@push('styles')
    <style>
        /* "px-20" is not a utility of the theme (only p-20) - without this the fields touch the card border */
        .content-wrapper .px-20 { padding-left: 20px !important; padding-right: 20px !important; }
        #al-history-table td:nth-child(3) { min-width: 150px; } /* rule name: not 5 lines of 2 words */
        .al-table th { font-size: 12px; font-weight: 500; color: #66717f; border-top: 0; white-space: nowrap; }
        .al-table td { vertical-align: middle; }
        .al-event-message { min-width: 340px; max-width: 560px; font-size: 14px; }
        .al-rule-cell { max-width: 200px; }
        .al-event-details { font-size: 12px; color: #66717f; }
        .al-row-open { background: #fffaf0; }
        .al-row-highlight { box-shadow: inset 3px 0 0 #1d6fd6; background: #eef5fd; }
        .al-row-recovered .al-event-message { color: #4f5b6b; }
        .al-filters .form-control { min-width: 140px; max-width: 240px; }
        .al-tabs .nav-link { color: #4f5b6b; }
        .al-tabs .nav-link.active { color: #1d2633; font-weight: 500; }
    </style>
@endpush

@section('content')
    @php
        $severityClass = ['info' => 'info', 'warning' => 'warning', 'critical' => 'danger'];
        $tz = $companyTimezone;
        $query = array_filter($filters, fn($value) => $value !== null && $value !== '');
    @endphp

    <div class="content-wrapper">

        <p class="f-14 text-dark-grey mb-3">@lang('databackd-gaps-alerts.history_subtitle')</p>

        @include('databackd.gaps.alerts._tabs')

        <div class="card bg-white border-0 b-shadow-4 mb-3">
            <form method="GET" action="{{ route('databackd.gaps.alerts.history') }}" class="d-flex flex-wrap align-items-end al-filters px-20 py-3 border-bottom">
                <div class="mr-3 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="al-f-severity">@lang('databackd-gaps-alerts.table.severity')</label>
                    <select name="severity" id="al-f-severity" class="form-control height-35 f-14">
                        <option value="">@lang('databackd-gaps-alerts.filters.all')</option>
                        @foreach (\App\Models\Databackd\FinAlertRule::SEVERITIES as $severity)
                            <option value="{{ $severity }}" @selected($filters['severity'] === $severity)>@lang('databackd-gaps-alerts.severities.' . $severity)</option>
                        @endforeach
                    </select>
                </div>
                <div class="mr-3 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="al-f-rule">@lang('databackd-gaps-alerts.table.rule')</label>
                    <select name="rule" id="al-f-rule" class="form-control height-35 f-14">
                        <option value="">@lang('databackd-gaps-alerts.filters.all')</option>
                        @foreach ($ruleOptions as $option)
                            <option value="{{ $option->id }}" @selected((string)$filters['rule'] === (string)$option->id)>{{ $option->name }}</option>
                        @endforeach
                        @if ($hasDeletedRules)
                            <option value="deleted" @selected($filters['rule'] === 'deleted')>@lang('databackd-gaps-alerts.filters.deleted_rules')</option>
                        @endif
                    </select>
                </div>
                <div class="mr-3 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="al-f-status">@lang('databackd-gaps-alerts.table.status')</label>
                    <select name="status" id="al-f-status" class="form-control height-35 f-14">
                        <option value="">@lang('databackd-gaps-alerts.filters.all')</option>
                        <option value="open" @selected($filters['status'] === 'open')>@lang('databackd-gaps-alerts.statuses.open')</option>
                        <option value="acknowledged" @selected($filters['status'] === 'acknowledged')>@lang('databackd-gaps-alerts.statuses.acknowledged')</option>
                    </select>
                </div>
                <div class="mr-3 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="al-f-from">@lang('databackd-gaps-alerts.filters.from')</label>
                    <input type="date" name="from" id="al-f-from" class="form-control height-35 f-14" value="{{ $filters['from'] }}">
                </div>
                <div class="mr-3 mb-2">
                    <label class="f-12 text-dark-grey mb-1" for="al-f-to">@lang('databackd-gaps-alerts.filters.to')</label>
                    <input type="date" name="to" id="al-f-to" class="form-control height-35 f-14" value="{{ $filters['to'] }}">
                </div>
                <div class="mb-2">
                    <button type="submit" class="btn btn-primary rounded f-14 p-2 mr-2"><i class="fa fa-filter mr-1"></i>@lang('databackd-gaps-alerts.filters.apply')</button>
                    @if ($filtered)
                        <a href="{{ route('databackd.gaps.alerts.history') }}" class="btn btn-outline-secondary rounded f-14 p-2"><i class="fa fa-times mr-1"></i>@lang('databackd-gaps-alerts.filters.reset')</a>
                    @endif
                </div>
            </form>

            <div class="d-flex flex-wrap align-items-center justify-content-between px-20 py-2 border-bottom">
                <span class="f-13 text-dark-grey">
                    @lang('databackd-gaps-alerts.history.count', ['shown' => $events->total(), 'total' => $totalCount, 'open' => $openCount])
                </span>
                @if ($openCount > 0)
                    <button type="button" class="btn btn-sm btn-outline-primary f-13" id="al-ack-all">
                        <i class="fa fa-check-double mr-1"></i>{{ $filtered ? __('databackd-gaps-alerts.buttons.acknowledge_filtered') : __('databackd-gaps-alerts.buttons.acknowledge_all') }}
                    </button>
                @endif
            </div>

            @if ($events->isEmpty())
                <div class="text-center py-5">
                    <i class="fa fa-bell-slash f-36 text-lightest mb-3"></i>
                    <div class="f-15 text-dark mb-1">{{ $filtered ? __('databackd-gaps-alerts.history.empty_filtered') : __('databackd-gaps-alerts.history.empty') }}</div>
                    @unless ($filtered)
                        <div class="f-13 text-lightest">@lang('databackd-gaps-alerts.history.empty_hint')</div>
                    @endunless
                </div>
            @else
                <div class="table-responsive">
                    <table class="table table-hover mb-0 al-table" id="al-history-table">
                        <thead>
                            <tr>
                                <th class="pl-20">@lang('databackd-gaps-alerts.table.date')</th>
                                <th>@lang('databackd-gaps-alerts.table.severity')</th>
                                <th>@lang('databackd-gaps-alerts.table.rule')</th>
                                <th>@lang('databackd-gaps-alerts.table.alert')</th>
                                <th class="text-right">@lang('databackd-gaps-alerts.table.value')</th>
                                <th>@lang('databackd-gaps-alerts.table.channels')</th>
                                <th>@lang('databackd-gaps-alerts.table.status')</th>
                                <th class="text-right pr-20">@lang('databackd-gaps-alerts.table.actions')</th>
                            </tr>
                        </thead>
                        <tbody>
                            @foreach ($events as $event)
                                @php
                                    $context = (array)$event->context;
                                    $recovered = ($context['kind'] ?? 'triggered') === 'recovered';
                                    // events written by other code may have no context: take the format from the type of the rule
                                    $ratioFormat = ['percent' => 'percent', 'days' => 'days', 'currency' => 'money'][$ratioFormats[$event->rule?->params['ratio_id'] ?? 0] ?? ''] ?? 'ratio';
                                    $fmt = $context['fmt'] ?? ['cash_below' => 'money', 'receivables_above' => 'money', 'budget_deviation' => 'pct', 'ratio_range' => $ratioFormat][$event->rule?->type] ?? 'ratio';
                                    $currency = $context['currency'] ?? (company()->currency->currency_code ?? 'EUR');
                                    $ruleName = $event->rule?->name ?? ($context['rule_name'] ?? null);
                                    $items = (array)($context['details']['items'] ?? []);
                                    $classes = trim(($event->acknowledged_at ? '' : 'al-row-open ') . ($recovered ? 'al-row-recovered ' : '') . ($highlight === (int)$event->id ? 'al-row-highlight' : ''));
                                @endphp
                                <tr id="al-event-{{ $event->id }}" class="{{ $classes }}">
                                    <td class="pl-20 f-13 text-nowrap">
                                        {{ $event->created_at->copy()->timezone($tz)->format('d.m.Y') }}
                                        <div class="f-12 text-lightest">{{ $event->created_at->copy()->timezone($tz)->format('H:i') }}</div>
                                    </td>
                                    <td>
                                        <span class="badge badge-{{ $severityClass[$event->severity] ?? 'secondary' }}">@lang('databackd-gaps-alerts.severities.' . $event->severity)</span>
                                        @if ($recovered)
                                            <span class="badge badge-success mt-1">@lang('databackd-gaps-alerts.actions.recovered')</span>
                                        @endif
                                    </td>
                                    <td class="f-14 al-rule-cell">
                                        @if ($event->rule)
                                            <span class="f-w-500 text-dark">{{ $ruleName }}</span>
                                        @elseif ($ruleName)
                                            <span class="text-dark-grey">{{ $ruleName }}</span>
                                            <div class="f-12 text-lightest">@lang('databackd-gaps-alerts.history.rule_deleted')</div>
                                        @else
                                            <span class="text-lightest">@lang('databackd-gaps-alerts.history.rule_deleted')</span>
                                        @endif
                                    </td>
                                    <td class="al-event-message">
                                        {{ \App\Services\Databackd\Gaps\Alerts\AlertText::eventMessage($event) }}
                                        @if ($event->period_from || $event->period_to)
                                            <div class="al-event-details">
                                                <i class="fa fa-calendar-alt mr-1"></i>{{ \App\Services\Databackd\Gaps\Alerts\AlertText::period($event->period_from, $event->period_to) }}
                                            </div>
                                        @endif
                                        @if ($items)
                                            <div class="al-event-details">
                                                @foreach ($items as $key => $item)
                                                    <span class="mr-2">{{ __('databackd-gaps-alerts.event.subject_names.' . $key) }}: {{ \App\Services\Databackd\Gaps\Alerts\AlertText::format('pct', (float)$item['pct']) }}</span>
                                                @endforeach
                                            </div>
                                        @endif
                                    </td>
                                    <td class="text-right f-14 text-nowrap">
                                        {{ $event->value === null ? '—' : \App\Services\Databackd\Gaps\Alerts\AlertText::format($fmt, (float)$event->value, $currency) }}
                                        @if ($event->threshold !== null)
                                            <div class="f-12 text-lightest">@lang('databackd-gaps-alerts.history.threshold_line', ['value' => \App\Services\Databackd\Gaps\Alerts\AlertText::format($fmt === 'pct' ? 'percent' : $fmt, (float)$event->threshold, $currency)])</div>
                                        @endif
                                    </td>
                                    <td class="f-14 text-nowrap">
                                        @if ($event->notified_app)
                                            <i class="fa fa-bell text-primary mr-2" data-toggle="tooltip" data-original-title="{{ __('databackd-gaps-alerts.channels.app') }}"></i>
                                        @endif
                                        @if ($event->notified_email)
                                            <i class="fa fa-envelope text-primary" data-toggle="tooltip" data-original-title="{{ __('databackd-gaps-alerts.channels.email') }}"></i>
                                        @endif
                                        @if (!$event->notified_app && !$event->notified_email)
                                            <span class="text-lightest">--</span>
                                        @endif
                                    </td>
                                    <td class="f-13">
                                        @if ($event->acknowledged_at)
                                            <span class="text-success"><i class="fa fa-check-circle mr-1"></i>@lang('databackd-gaps-alerts.statuses.acknowledged')</span>
                                            @if ($event->acknowledged_by && isset($ackUsers[$event->acknowledged_by]))
                                                <div class="f-12 text-lightest">{{ $ackUsers[$event->acknowledged_by] }}, {{ $event->acknowledged_at->copy()->timezone($tz)->format('d.m.Y H:i') }}</div>
                                            @elseif ($recovered)
                                                <div class="f-12 text-lightest">@lang('databackd-gaps-alerts.history.auto')</div>
                                            @endif
                                        @else
                                            <span class="text-warning"><i class="fa fa-exclamation-circle mr-1"></i>@lang('databackd-gaps-alerts.statuses.open')</span>
                                        @endif
                                    </td>
                                    <td class="text-right text-nowrap pr-20">
                                        @unless ($event->acknowledged_at)
                                            <button type="button" class="btn btn-sm btn-outline-primary f-13 al-ack" data-id="{{ $event->id }}">
                                                <i class="fa fa-check mr-1"></i>@lang('databackd-gaps-alerts.buttons.acknowledge')
                                            </button>
                                        @endunless
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                </div>

                @if ($events->hasPages())
                    <div class="px-20 py-3 d-flex justify-content-end">{{ $events->onEachSide(1)->links('pagination::bootstrap-4') }}</div>
                @endif
            @endif
        </div>

    </div>
@endsection

@push('scripts')
    <script>
        $(function () {
            var csrf = "{{ csrf_token() }}";
            var ackUrl = "{{ route('databackd.gaps.alerts.acknowledge', ':id') }}";
            var ackAllUrl = "{{ route('databackd.gaps.alerts.acknowledge-all') }}";
            var filters = @json($query);

            $('body').on('click', '.al-ack', function () {
                $.easyAjax({
                    type: 'POST',
                    url: ackUrl.replace(':id', $(this).data('id')),
                    blockUI: true,
                    data: {'_token': csrf},
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });

            $('#al-ack-all').on('click', function () {
                $.easyAjax({
                    type: 'POST',
                    url: ackAllUrl,
                    blockUI: true,
                    data: $.extend({'_token': csrf}, filters),
                    success: function (response) {
                        if (response.status === 'success') {
                            window.location.reload();
                        }
                    }
                });
            });

            var $highlight = $('.al-row-highlight');

            if ($highlight.length) {
                $('html, body').animate({scrollTop: $highlight.first().offset().top - 160}, 200);
            }
        });
    </script>
@endpush

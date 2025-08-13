<?php

namespace App\Http\Controllers\Databackd\Gaps\Alerts;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Models\Databackd\FinAlertEvent;
use App\Models\Databackd\FinAlertRule;
use App\Models\Databackd\FinRatio;
use App\Models\User;
use App\Notifications\Databackd\FinAlertNotification;
use Carbon\Carbon;
use Illuminate\Http\Request;

/**
 * History of the fired alerts (fin_alert_events) with filters (severity, rule, status, period) and
 * confirmation ("acknowledged") of the alerts. Confirming an alert also marks its bell notification as read.
 */
class AlertHistoryController extends AccountBaseController
{

    const PER_PAGE = 25;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-alerts.history_title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $this->pageTitle = __('databackd-gaps-alerts.history_title');

        $filters = $this->filters($request);
        $query = $this->filteredQuery($filters)->with('rule:id,name,type,params'); // type + params: value format of events without context.fmt (demo data)

        $events = $query->orderByDesc('created_at')->orderByDesc('id')->paginate(self::PER_PAGE)->withQueryString();

        $this->events = $events;
        $this->filters = $filters;
        $this->filtered = (bool)array_filter($filters, fn($value) => $value !== null && $value !== '');
        $this->ruleOptions = FinAlertRule::orderBy('name')->get(['id', 'name']);
        $this->ratioFormats = FinRatio::pluck('format', 'id');
        $this->hasDeletedRules = FinAlertEvent::whereNull('rule_id')->exists();
        $this->ackUsers = User::whereIn('id', $events->pluck('acknowledged_by')->filter()->unique()->all())->pluck('name', 'id');
        $this->openCount = FinAlertEvent::whereNull('acknowledged_at')->count();
        $this->totalCount = FinAlertEvent::count();
        $this->highlight = $request->integer('event') ?: null;
        $this->companyTimezone = company()->timezone;
        $this->alertTab = 'history';

        return view('databackd.gaps.alerts.history', $this->data);
    }

    /** Confirm one alert. */
    public function acknowledge($id)
    {
        $event = FinAlertEvent::findOrFail($id);

        if (!$event->acknowledged_at) {
            $event->forceFill(['acknowledged_at' => now(), 'acknowledged_by' => user()->id])->save();
        }

        $this->markNotificationsRead([(int)$event->id]);

        return Reply::successWithData(__('databackd-gaps-alerts.messages.acknowledged'), ['open' => FinAlertEvent::whereNull('acknowledged_at')->count()]);
    }

    /** Confirm all open alerts that match the filters of the history screen. */
    public function acknowledgeAll(Request $request)
    {
        $ids = $this->filteredQuery($this->filters($request))->whereNull('acknowledged_at')->pluck('id')->map(fn($id) => (int)$id)->all();

        if ($ids) {
            FinAlertEvent::whereIn('id', $ids)->update(['acknowledged_at' => now(), 'acknowledged_by' => user()->id]);
            $this->markNotificationsRead($ids);
        }

        return Reply::success(trans_choice('databackd-gaps-alerts.messages.acknowledged_all', count($ids), ['count' => count($ids)]));
    }

    // ------------------------------------------------------------------

    /**
     * @return array{severity: ?string, rule: ?string, status: ?string, from: ?string, to: ?string}
     */
    protected function filters(Request $request): array
    {
        $severity = $request->input('severity');
        $rule = $request->input('rule');
        $status = $request->input('status');

        return [
            'severity' => in_array($severity, FinAlertRule::SEVERITIES, true) ? $severity : null,
            'rule' => $rule === 'deleted' ? 'deleted' : ($request->integer('rule') ?: null),
            'status' => in_array($status, ['open', 'acknowledged'], true) ? $status : null,
            'from' => $this->date($request->input('from')),
            'to' => $this->date($request->input('to')),
        ];
    }

    protected function filteredQuery(array $filters)
    {
        $query = FinAlertEvent::query();
        $timezone = company()->timezone ?: config('app.timezone');

        if ($filters['severity']) {
            $query->where('severity', $filters['severity']);
        }

        if ($filters['rule'] === 'deleted') {
            $query->whereNull('rule_id');
        } elseif ($filters['rule']) {
            $query->where('rule_id', (int)$filters['rule']);
        }

        if ($filters['status'] === 'open') {
            $query->whereNull('acknowledged_at');
        } elseif ($filters['status'] === 'acknowledged') {
            $query->whereNotNull('acknowledged_at');
        }

        // the dates are days of the company time zone, created_at is stored in the application time zone
        if ($filters['from']) {
            $query->where('created_at', '>=', Carbon::parse($filters['from'], $timezone)->startOfDay()->timezone(config('app.timezone')));
        }

        if ($filters['to']) {
            $query->where('created_at', '<=', Carbon::parse($filters['to'], $timezone)->endOfDay()->timezone(config('app.timezone')));
        }

        return $query;
    }

    protected function date($value): ?string
    {
        if (!is_string($value) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $value)) {
            return null;
        }

        try {
            return Carbon::createFromFormat('Y-m-d', $value)->toDateString();
        } catch (\Throwable $e) {
            return null;
        }
    }

    /** Marks the unread bell notifications of the user for the alerts as read. */
    protected function markNotificationsRead(array $eventIds): void
    {
        $user = User::find(user()->id);

        foreach ($user->unreadNotifications()->where('type', FinAlertNotification::class)->get() as $notification) {
            if (in_array((int)($notification->data['event_id'] ?? 0), $eventIds, true)) {
                $notification->markAsRead();
            }
        }
    }

}

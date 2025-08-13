<?php

namespace App\Http\Controllers\Databackd\Gaps\Alerts;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Gaps\Alerts\StoreAlertRuleRequest;
use App\Http\Requests\Databackd\Gaps\Alerts\UpdateAlertRuleRequest;
use App\Models\Databackd\FinAlertEvent;
use App\Models\Databackd\FinAlertRule;
use App\Models\Databackd\FinBudget;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinRatio;
use App\Services\Databackd\Gaps\Alerts\AlertChecker;
use Illuminate\Http\Request;

/**
 * Alert rules (thresholds): list with the state of every rule, create / edit / delete, switch on and off,
 * and "Check now". The history of the fired alerts is AlertHistoryController, the scheduled check is the
 * `databackd:check-alerts` command (both use App\Services\Databackd\Gaps\Alerts\AlertChecker).
 *
 * Everybody with the module can see the rules; changing them and checking is for the admins.
 */
class AlertRuleController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-alerts.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
        $this->middleware(function ($request, $next) {
            abort_403(!in_array('admin', user_roles()));

            return $next($request);
        })->except(['index']);
    }

    // ------------------------------------------------------------------
    // Pages
    // ------------------------------------------------------------------

    public function index()
    {
        $this->pageTitle = __('databackd-gaps-alerts.title');

        $rules = FinAlertRule::with('entity:id,name')->orderByDesc('is_active')->orderBy('name')->get();

        $this->rules = $rules;
        $this->ratios = FinRatio::get()->keyBy('id');
        $this->budgets = FinBudget::with('entity:id,name')->get()->keyBy('id');
        $this->currency = company()->currency->currency_code ?? 'EUR';
        $this->canManage = in_array('admin', user_roles());
        $this->activeCount = $rules->where('is_active', true)->count();
        $this->triggeredCount = $rules->where('is_active', true)->where('last_state', 'triggered')->count();
        $this->openCount = FinAlertEvent::whereNull('acknowledged_at')->count();
        $this->lastChecked = $rules->max('last_checked_at');
        $this->checkResult = session('alert_check');
        $this->alertTab = 'rules';

        return view('databackd.gaps.alerts.index', $this->data);
    }

    public function create()
    {
        $this->pageTitle = __('databackd-gaps-alerts.form.title_create');
        $this->rule = new FinAlertRule([
            'type' => 'ratio_range', 'severity' => 'warning', 'notify_app' => true, 'notify_email' => false,
            'is_active' => true, 'params' => [],
        ]);

        return $this->formView();
    }

    public function edit($id)
    {
        $this->pageTitle = __('databackd-gaps-alerts.form.title_edit');
        $this->rule = FinAlertRule::findOrFail($id);

        return $this->formView();
    }

    // ------------------------------------------------------------------
    // Actions
    // ------------------------------------------------------------------

    public function store(StoreAlertRuleRequest $request)
    {
        $rule = new FinAlertRule();
        $rule->company_id = company()->id;
        $rule->created_by = user()->id;
        $rule->last_state = 'ok';
        $rule->fill($request->ruleAttributes());
        $rule->save();

        return Reply::redirect(route('databackd.gaps.alerts.index'), __('databackd-gaps-alerts.messages.created'));
    }

    public function update(UpdateAlertRuleRequest $request, $id)
    {
        $rule = FinAlertRule::findOrFail($id);
        $attributes = $request->ruleAttributes();

        // another condition = another situation: the next check may fire a new alert
        if ($rule->type !== $attributes['type'] || (int)$rule->entity_id !== (int)$attributes['entity_id']
            || json_encode($rule->params) !== json_encode($attributes['params'])) {
            $rule->last_state = 'ok';
        }

        $rule->fill($attributes);
        $rule->save();

        return Reply::redirect(route('databackd.gaps.alerts.index'), __('databackd-gaps-alerts.messages.updated'));
    }

    /** Switch a rule on / off (a switched off rule is skipped by the check). */
    public function toggle($id)
    {
        $rule = FinAlertRule::findOrFail($id);
        $rule->is_active = !$rule->is_active;
        $rule->save();

        return Reply::success(__($rule->is_active ? 'databackd-gaps-alerts.messages.activated' : 'databackd-gaps-alerts.messages.deactivated'));
    }

    /** The alerts of a deleted rule stay in the history (rule_id becomes null, the name is kept in their context). */
    public function destroy($id)
    {
        $rule = FinAlertRule::findOrFail($id);

        FinAlertEvent::where('rule_id', $rule->id)->update(['rule_id' => null]);
        $rule->delete();

        return Reply::success(__('databackd-gaps-alerts.messages.deleted'));
    }

    /**
     * "Check now": the same check as `databackd:check-alerts` for the current company. With dry_run only the
     * evaluation is shown (no alerts are written, nobody is notified). The result is shown on the list.
     */
    public function check(Request $request)
    {
        $dryRun = $request->boolean('dry_run');
        $summary = (new AlertChecker())->checkCompany(company()->id, $dryRun);

        return redirect()->route('databackd.gaps.alerts.index')->with('alert_check', $summary);
    }

    // ------------------------------------------------------------------

    protected function formView()
    {
        $this->ratios = FinRatio::where('is_active', true)->orderBy('sort')->orderBy('name')->get();
        $this->budgets = databackd_module('databackd_planning') || $this->rule->type === 'budget_deviation'
            ? FinBudget::orderByDesc('year')->orderBy('name')->get() : collect();
        $this->entities = FinEntity::orderBy('sort')->orderBy('name')->get(['id', 'name']);
        $this->entityNames = $this->entities->pluck('name', 'id');
        $this->ruleTypes = StoreAlertRuleRequest::availableTypes($this->rule->exists ? $this->rule->type : null);
        $this->ruleParams = (array)$this->rule->params;
        $this->recipientsText = implode(', ', (array)$this->rule->recipients);
        $this->isEdit = $this->rule->exists;

        return view('databackd.gaps.alerts.form', $this->data);
    }

}

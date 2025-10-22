<?php

namespace App\Http\Controllers\Databackd\Reports;

use App\Http\Controllers\AccountBaseController;
use App\Services\Databackd\Reports\DashboardMetrics;
use App\Services\Databackd\Reports\ReportFilters;
use Illuminate\Support\Facades\Route;

/**
 * Financial dashboard (F8, R8): KPI cards and seven charts, calculated live from fin_entries.
 */
class FinDashboardController extends AccountBaseController
{

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-reports-dashboard.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index()
    {
        $filters = ReportFilters::fromRequest(request());

        // The dashboard always compares with the previous period unless the user picked "no comparison"
        if (!request()->has('compare')) {
            $filters->compare = 'previous';
        }

        $metrics = new DashboardMetrics(company()->id);
        $comparePeriod = DashboardMetrics::comparePeriod($filters);
        $firstFlow = $comparePeriod ? $metrics->firstFlowDate($filters) : null;

        $this->filters = $filters;
        $this->hasAnyData = $metrics->hasAnyData();
        $this->kpis = $metrics->kpis($filters);
        $this->charts = $metrics->charts($filters);
        $this->comparePeriod = $comparePeriod;
        // the comparison period starts before the first record: the changes of revenue / expense / profit look too large.
        // Review A: compared as Y-m-d - the period is in the company timezone, the entry date is a plain date (parsed in UTC
        // it was "after" the midnight of Sofia on the same day and the note showed up for a fully covered period)
        $firstDay = $firstFlow?->toDateString();
        $this->comparePartialFrom = $firstDay && $comparePeriod
            && $firstDay > $comparePeriod['from']->toDateString() && $firstDay <= $comparePeriod['to']->toDateString()
            ? $firstFlow : null;
        $this->expensesNotSplit = DashboardMetrics::filtersSplitRevenueOnly($filters);
        $this->revenueNotSplit = DashboardMetrics::filtersSplitExpenseOnly($filters);
        $this->dataAsOf = now(company()->timezone ?: config('app.timezone'))->format('d.m.Y H:i');
        $this->currencyCode = company()->currency->currency_code ?? 'EUR';
        $this->importUrl = Route::has('databackd.data.import.index') && databackd_module('databackd_data') ? route('databackd.data.import.index') : null;
        $this->entriesUrl = Route::has('databackd.data.entries.index') && databackd_module('databackd_data') ? route('databackd.data.entries.index') : null;
        $this->onboardingUrl = Route::has('databackd.gaps.onboarding.index') && databackd_module('databackd_data') && in_array('admin', user_roles())
            ? route('databackd.gaps.onboarding.index') : null;

        return view('databackd.reports.dashboard.index', $this->data);
    }

}

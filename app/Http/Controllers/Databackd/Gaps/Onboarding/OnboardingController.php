<?php

namespace App\Http\Controllers\Databackd\Gaps\Onboarding;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Gaps\Onboarding\ApplyChartRequest;
use App\Http\Requests\Databackd\Gaps\Onboarding\MarkStepRequest;
use App\Http\Requests\Databackd\Gaps\Onboarding\SaveCompanyRequest;
use App\Http\Requests\Databackd\Gaps\Onboarding\SaveEntitiesRequest;
use App\Models\Company;
use App\Models\CompanyAddress;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinOnboarding;
use App\Services\Databackd\Gaps\Onboarding\OnboardingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Route;

/**
 * Setup wizard for a new company (R9): company data -> legal entities -> chart of accounts -> first import -> dashboard.
 * Admin only. The wizard state lives in fin_onboarding (see OnboardingService).
 */
class OnboardingController extends AccountBaseController
{

    protected OnboardingService $service;

    public function __construct()
    {
        parent::__construct();

        $this->service = new OnboardingService();

        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-onboarding.title');
            abort_403(!in_array('admin', user_roles()));

            return $next($request);
        });
    }

    /**
     * The wizard. ?step=1..5 shows a step; without it the first pending step is shown.
     */
    public function index(Request $request)
    {
        $companyId = company()->id;
        $keys = FinOnboarding::STEPS;

        $onboarding = $this->service->forCompany($companyId);
        $this->service->syncDetected($onboarding);

        $progress = $this->service->progress($onboarding);
        $stepNo = (int)$request->query('step');

        if ($stepNo < 1 || $stepNo > count($keys)) {
            $stepNo = array_search($progress['current'], $keys, true) + 1;
        }

        $stepKey = $keys[$stepNo - 1];

        $this->onboarding = $onboarding;
        $this->steps = $this->service->steps($onboarding);
        $this->progress = $progress;
        $this->stepKeys = $keys;
        $this->stepNo = $stepNo;
        $this->stepKey = $stepKey;
        $this->summary = $this->service->summary($companyId);
        $this->nextUrl = $stepNo < count($keys) ? $this->stepUrl($stepNo + 1) : null;
        $this->prevUrl = $stepNo > 1 ? $this->stepUrl($stepNo - 1) : null;

        $this->{'load' . ucfirst($stepKey) . 'Step'}($companyId);

        return view('databackd.gaps.onboarding.index', $this->data);
    }

    /**
     * Step 1: company name, EIK / VAT number, address (company + its default business address).
     */
    public function saveCompany(SaveCompanyRequest $request)
    {
        DB::transaction(function () use ($request) {
            $company = Company::findOrFail(company()->id);
            $company->company_name = $request->company_name;
            $company->address = $request->address;
            $company->save();

            $address = CompanyAddress::where('is_default', 1)->first();

            if (!$address) {
                $address = new CompanyAddress();
                $address->company_id = $company->id;
                $address->is_default = 1;
            }

            $address->address = $request->address;
            $address->tax_number = filled($request->tax_number) ? $request->tax_number : null;

            if ($address->tax_number && blank($address->tax_name)) {
                $address->tax_name = __('databackd-gaps-onboarding.company.tax_name');
            }

            $address->save();
        });

        $this->markDone('company');

        return Reply::redirect($this->stepUrl(2), __('databackd-gaps-onboarding.messages.company_saved'));
    }

    /**
     * Step 2: the main legal entity (only while the company has none) and the subsidiaries under the first root entity.
     */
    public function saveEntities(SaveEntitiesRequest $request)
    {
        $companyId = company()->id;
        $created = 0;

        DB::transaction(function () use ($request, $companyId, &$created) {
            $sort = (int)FinEntity::max('sort');
            $root = FinEntity::whereNull('parent_id')->orderBy('sort')->orderBy('id')->first();

            if (!$root && filled($request->main_name)) {
                $root = $this->createEntity($companyId, [
                    'name' => $request->main_name,
                    'eik' => $request->main_eik,
                    'country' => $request->main_country,
                    'currency' => $request->main_currency,
                ], null, 100, ++$sort);
                $created++;
            }

            foreach ((array)$request->sub_name as $index => $name) {
                if (!filled($name) || !$root) {
                    continue;
                }

                $pct = $request->input('sub_pct.' . $index);

                $this->createEntity($companyId, [
                    'name' => $name,
                    'eik' => $request->input('sub_eik.' . $index),
                    'country' => $request->input('sub_country.' . $index),
                    'currency' => $request->input('sub_currency.' . $index),
                ], $root->id, filled($pct) ? (float)$pct : 100, ++$sort);
                $created++;
            }
        });

        $this->markDone('entities');

        $message = $created > 0
            ? __('databackd-gaps-onboarding.messages.entities_saved', ['count' => $created])
            : __('databackd-gaps-onboarding.messages.step_done');

        return Reply::redirect($this->stepUrl(3), $message);
    }

    /**
     * Step 3: apply the chart-of-accounts template.
     */
    public function saveChart(ApplyChartRequest $request)
    {
        $result = $this->service->applyChartTemplate(company()->id, $request->template);

        $message = __('databackd-gaps-onboarding.messages.chart_applied.' . $result['template'], [
            'active' => count($result['active']),
            'inactive' => count($result['inactive']),
        ]);

        if ($result['kept_active']) {
            $message .= ' ' . __('databackd-gaps-onboarding.messages.chart_kept', [
                'count' => count($result['kept_active']),
                'codes' => implode(', ', $result['kept_active']),
            ]);
        }

        return Reply::redirect($this->stepUrl(4), $message);
    }

    /**
     * Mark a step done or skipped and go to the next one.
     */
    public function markStep(MarkStepRequest $request, string $step)
    {
        abort_if(!in_array($step, array_slice(FinOnboarding::STEPS, 0, -1), true), 404);

        $onboarding = $this->service->forCompany(company()->id);
        $this->service->markStep($onboarding, $step, $request->status);

        $number = array_search($step, FinOnboarding::STEPS, true) + 1;

        return Reply::redirect($this->stepUrl($number + 1), __('databackd-gaps-onboarding.messages.' . ($request->status === 'done' ? 'step_done' : 'step_skipped')));
    }

    /**
     * Step 5: finish the wizard and open the dashboard.
     */
    public function complete()
    {
        $onboarding = $this->service->forCompany(company()->id);
        $this->service->complete($onboarding);

        return Reply::redirect($this->dashboardUrl() ?: route('databackd.gaps.onboarding.index'), __('databackd-gaps-onboarding.messages.completed'));
    }

    /**
     * Hide the wizard (menu item and dashboard banner).
     */
    public function dismiss()
    {
        $onboarding = $this->service->forCompany(company()->id);
        $this->service->dismiss($onboarding);

        return Reply::success(__('databackd-gaps-onboarding.messages.dismissed'));
    }

    /**
     * Show a dismissed wizard again.
     */
    public function restore()
    {
        $onboarding = $this->service->forCompany(company()->id);
        $this->service->restore($onboarding);

        return Reply::redirect(route('databackd.gaps.onboarding.index'), __('databackd-gaps-onboarding.messages.restored'));
    }

    // ------------------------------------------------------------------ data of the steps

    protected function loadCompanyStep(int $companyId): void
    {
        $this->companyModel = Company::with('currency')->findOrFail($companyId);
        $this->address = CompanyAddress::where('is_default', 1)->first();
        $this->settingsUrl = Route::has('company-settings.index') ? route('company-settings.index') : null;
    }

    protected function loadEntitiesStep(int $companyId): void
    {
        $entities = FinEntity::orderBy('sort')->orderBy('id')->get();

        $this->entities = $entities;
        $this->entityNames = $entities->pluck('name', 'id');
        $this->hasEntities = $entities->isNotEmpty();
        $this->maxSubsidiaries = SaveEntitiesRequest::MAX_SUBSIDIARIES;
        $this->defaultName = $this->hasEntities ? '' : company()->company_name;
        $this->defaultEik = CompanyAddress::where('is_default', 1)->value('tax_number') ?: '';
        $this->entitiesUrl = Route::has('databackd.data.entities.index') ? route('databackd.data.entities.index') : null;
    }

    protected function loadChartStep(int $companyId): void
    {
        $this->templates = [
            'nss' => $this->service->templateAccounts('nss'),
            'micro' => $this->service->templateAccounts('micro'),
        ];
        $this->accountsUrl = Route::has('databackd.data.accounts.index') ? route('databackd.data.accounts.index') : null;
    }

    protected function loadImportStep(int $companyId): void
    {
        $this->importUrl = Route::has('databackd.data.import.index') ? route('databackd.data.import.index') : null;
        $this->templateXlsxUrl = Route::has('databackd.data.import.template') ? route('databackd.data.import.template', ['format' => 'xlsx']) : null;
        $this->templateCsvUrl = Route::has('databackd.data.import.template') ? route('databackd.data.import.template', ['format' => 'csv']) : null;
        // Google Sheets belong to the "integrations" module (the data sources screens)
        $this->sheetsUrl = Route::has('databackd.data.sources.index') && databackd_module('databackd_integrations')
            ? route('databackd.data.sources.index')
            : null;
        $this->sheetsInPackage = databackd_module('databackd_integrations');
    }

    protected function loadDashboardStep(int $companyId): void
    {
        $this->dashboardUrl = $this->dashboardUrl();
        $this->companyModel = Company::findOrFail($companyId);
    }

    // ------------------------------------------------------------------ helpers

    protected function markDone(string $step): void
    {
        $onboarding = $this->service->forCompany(company()->id);
        $this->service->markStep($onboarding, $step, 'done');
    }

    protected function stepUrl(int $number): string
    {
        return route('databackd.gaps.onboarding.index', ['step' => max(1, min($number, count(FinOnboarding::STEPS)))]);
    }

    protected function dashboardUrl(): ?string
    {
        return Route::has('databackd.reports.dashboard.index') && databackd_module('databackd_reports')
            ? route('databackd.reports.dashboard.index')
            : null;
    }

    protected function createEntity(int $companyId, array $data, ?int $parentId, float $pct, int $sort): FinEntity
    {
        $entity = new FinEntity();
        $entity->company_id = $companyId;
        $entity->name = trim($data['name']);
        $entity->eik = filled($data['eik']) ? trim($data['eik']) : null;
        $entity->country = filled($data['country']) ? strtoupper($data['country']) : 'BG';
        $entity->currency = filled($data['currency']) ? strtoupper($data['currency']) : 'EUR';
        $entity->parent_id = $parentId;
        $entity->ownership_pct = $pct;
        $entity->is_active = true;
        $entity->sort = $sort;
        $entity->save();

        return $entity;
    }

}

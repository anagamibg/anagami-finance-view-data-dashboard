<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\Databackd\FinOnboarding;
use App\Models\SuperAdmin\Package;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data of the onboarding wizard (B5) and of the packages (S8 Phase 0):
 *
 *  1. A finished onboarding of the demo company: one fin_onboarding row (one per company) with all five steps
 *     "done" (company, entities, chart, import, dashboard), chart template "nss", completed shortly after the first data
 *     import of the company. So the wizard does not show up for the demo company (a new company starts it).
 *  2. A new empty private package "Стартов": the same core modules as the "Starter" package plus databackd_data,
 *     databackd_reports, databackd_teams and databackd_integrations - WITHOUT databackd_analytics and
 *     databackd_planning (ratios, consolidation, budgets, forecasts). It has no companies and is private, so it is not
 *     in the public prices; assign it to a test company in the super admin panel to demonstrate the packages.
 *
 * Idempotent: the onboarding row is updated, the package is found by name and updated. No other package and no company
 * package is changed. Finds everything by name / email, never by a local id.
 */
class OnboardingAndPackagesDemoSeeder extends Seeder
{

    const PACKAGE_NAME = 'Стартов';
    const BASE_PACKAGE = 'Starter';

    /** AFV modules of the new package; databackd_analytics and databackd_planning are deliberately absent. */
    const DATABACKD_MODULES = ['databackd_data', 'databackd_reports', 'databackd_teams', 'databackd_integrations'];

    /** Core modules, used only when the "Starter" package does not exist in the database. */
    const CORE_MODULES = [
        'clients', 'employees', 'projects', 'attendance', 'tasks', 'estimates', 'invoices', 'payments', 'timelogs', 'tickets',
        'events', 'notices', 'leaves', 'leads', 'holidays', 'products', 'expenses', 'contracts', 'reports', 'orders',
        'knowledgebase', 'bankaccount', 'messages',
    ];

    const DESCRIPTION = 'Стартов пакет за малки фирми: въвеждане и импорт на данни, финансово табло и справки, услугата „Екипи“ и интеграции. Без коефициенти и консолидация, без бюджети и прогнози.';

    const FALLBACK_START = '2025-05-05 09:30:00';
    const FALLBACK_COMPLETED = '2025-07-08 10:48:00';

    protected int $companyId = 0;
    protected ?int $adminId = null;

    public function run(Company $company)
    {
        $this->companyId = $company->id;

        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');

        $this->onboarding();
        $package = $this->package();

        $this->command?->info('onboarding: 5 steps done; package "' . $package->name . '" (modules: ' . count(json_decode($package->module_in_package, true)) . ', private, '
            . $package->companies()->count() . ' companies)');
    }

    // ---------------------------------------------------------------------------------------------
    // Onboarding
    // ---------------------------------------------------------------------------------------------

    protected function onboarding(): void
    {
        // the wizard was finished in the same hour as the first import of the demo company (or on a fixed date)
        $firstImport = DB::table('fin_import_batches')
            ->where('company_id', $this->companyId)->whereIn('source', ['excel', 'csv', 'google_sheets', 'api'])->min('created_at');

        $completed = $firstImport ? \Carbon\Carbon::parse($firstImport)->addMinutes(36)->toDateTimeString() : self::FALLBACK_COMPLETED;
        $started = \Carbon\Carbon::parse(self::FALLBACK_START)->min(\Carbon\Carbon::parse($completed)->subDays(2))->toDateTimeString();

        $row = FinOnboarding::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->first() ?? new FinOnboarding();

        $row->forceFill([
            'company_id' => $this->companyId,
            'steps' => array_fill_keys(FinOnboarding::STEPS, 'done'),
            'chart_template' => 'nss',
            'completed_at' => $completed,
            'dismissed_at' => null,
            'created_by' => $this->adminId,
        ]);

        // both timestamps are fixed (a JSON key order change would otherwise bump updated_at to "now" on every run)
        $row->timestamps = false;
        $row->created_at = $started;
        $row->updated_at = $completed;
        $row->save();
    }

    // ---------------------------------------------------------------------------------------------
    // Package "Стартов"
    // ---------------------------------------------------------------------------------------------

    protected function package(): Package
    {
        $base = Package::where('name', self::BASE_PACKAGE)->first();
        $core = [];

        if ($base) {
            foreach ((array)json_decode((string)$base->module_in_package, true) as $module) {
                if (is_string($module) && !str_starts_with($module, 'databackd_') && !in_array($module, $core, true)) {
                    $core[] = $module;
                }
            }
        }

        $modules = array_values(array_merge($core ?: self::CORE_MODULES, self::DATABACKD_MODULES));

        $package = Package::where('name', self::PACKAGE_NAME)->first() ?? new Package();

        $package->name = self::PACKAGE_NAME;
        $package->description = self::DESCRIPTION;
        $package->currency_id = $base->currency_id ?? Package::query()->value('currency_id');
        $package->price = null;
        $package->max_storage_size = $base->max_storage_size ?? 500;
        $package->ai_chatgpt_tokens = null;
        $package->max_file_size = $base->max_file_size ?? 30;
        $package->annual_price = 290;
        $package->monthly_price = 29;
        $package->billing_cycle = $base->billing_cycle ?? 10;
        $package->max_employees = $base->max_employees ?? 50;
        // the limits of clients / invoices are explicit (an empty limit would block adding the first client / invoice)
        $package->max_clients = 100;
        $package->max_invoices = 1000;
        $package->sort = '5';
        $package->module_in_package = json_encode($modules);
        $package->stripe_annual_plan_id = null;
        $package->stripe_monthly_plan_id = null;
        $package->razorpay_annual_plan_id = null;
        $package->razorpay_monthly_plan_id = null;
        $package->paystack_annual_plan_id = null;
        $package->paystack_monthly_plan_id = null;
        $package->default = 'no';
        $package->is_private = 1;
        $package->storage_unit = $base->storage_unit ?? 'mb';
        $package->is_recommended = 0;
        $package->is_free = 0;
        $package->is_auto_renew = 0;
        $package->monthly_status = '1';
        $package->annual_status = '1';
        $package->package = null;
        $package->save();

        return $package;
    }

}

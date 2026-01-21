<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data of the product margins report (B2) and of the exchange-rate screen (B6):
 *
 *  1. Cost price (products.cost_price) of the 20 demo products, found by SKU (DFG-001 ... DFG-020). The margins
 *     range from ~17% to ~65%; two products are sold below cost (negative margin) and two have no cost price at all
 *     (the report warns about them). products.price is a varchar and is not touched.
 *  2. Exchange rates (fin_fx_rates) for 18 months (May 2025 - October 2026), base EUR, quoted in the ECB way
 *     (units of currency for 1 EUR): USD, GBP, CHF, PLN, RON and BGN (fixed 1.95583). One rate per Friday, one for the
 *     last business day of every month and one for the demo "today" (2026-10-27). The rates of the quarter ends are
 *     marked source = manual (entered by the accountant), the BGN rates after the euro adoption (2026) as well;
 *     all other rates are source = ecb.
 *
 * Idempotent: the cost prices are updated by SKU; the exchange rates of the period created by the demo administrator
 * (created_by) are removed and rebuilt, rates of other users / jobs on the same dates are kept (insert ignore).
 * Does not rely on local ids - everything is found by SKU, email or name.
 */
class ProductCostAndFxDemoSeeder extends Seeder
{

    /** SKU => cost price (EUR, per unit as sold). null = no cost price (to show the warning of the margins report). */
    const COSTS = [
        'DFG-001' => 72.00,    // Monthly bookkeeping - micro firm, price 120 (40%)
        'DFG-002' => 140.00,   // small firm, price 250 (44%)
        'DFG-003' => 300.00,   // medium firm, price 480 (37.5%)
        'DFG-004' => 225.00,   // Annual financial statement, price 350 (35.7%)
        'DFG-005' => 118.00,   // Annual tax return, price 180 (34.4%)
        'DFG-006' => 13.80,    // Payroll processing per employee, price 12 (negative: -15%)
        'DFG-007' => 120.00,   // Monthly financial analysis, price 300 (60%)
        'DFG-008' => 88.00,    // Ratio analysis, price 220 (60%)
        'DFG-009' => 110.00,   // Cash flow analysis, price 260 (57.7%)
        'DFG-010' => 286.00,   // Budgeting and 12-month forecast, price 650 (56%)
        'DFG-011' => 585.00,   // Consolidated reporting, price 900 (35%)
        'DFG-012' => 38.00,    // Consultation - analyst, price 55 (30.9%)
        'DFG-013' => 62.50,    // Consultation - senior analyst, price 75 (16.7%)
        'DFG-014' => 58.00,    // Tax planning consultation, price 90 (35.6%)
        'DFG-015' => 880.00,   // Investment project appraisal, price 800 (negative: -10%)
        'DFG-016' => 10.15,    // Licence Start per user, price 29 (65%)
        'DFG-017' => 22.00,    // Licence Business per user, price 59 (62.7%)
        'DFG-018' => null,     // Implementation and set-up - no cost price yet
        'DFG-019' => 128.00,   // Training - financial analysis basics, price 190 (32.6%)
        'DFG-020' => null,     // Training - budgeting for managers - no cost price yet
    ];

    const FX_START = '2025-05-01';
    const FX_END = '2026-10-27';
    const FX_CURRENCIES = ['USD', 'GBP', 'CHF', 'PLN', 'RON', 'BGN'];
    const BGN_RATE = 1.95583;
    const BGN_EURO_ADOPTION = '2026-01-01';

    /** Quarter ends: the rate of the last business day is entered by hand (source = manual). */
    const MANUAL_DATES = ['2025-06-30', '2025-09-30', '2025-12-31', '2026-03-31', '2026-06-30', '2026-09-30'];

    /**
     * Monthly anchors (the rate around the 15th of the month), units of currency for 1 EUR.
     * Months from 2025-05 to 2026-10, in order. The path between the anchors is linear, a small AR(1) noise is added.
     */
    const ANCHORS = [
        'USD' => [1.128, 1.152, 1.168, 1.165, 1.173, 1.163, 1.158, 1.170, 1.176, 1.182, 1.171, 1.162, 1.155, 1.149, 1.158, 1.167, 1.160, 1.152],
        'GBP' => [0.845, 0.850, 0.862, 0.866, 0.870, 0.874, 0.877, 0.872, 0.868, 0.866, 0.861, 0.857, 0.854, 0.859, 0.863, 0.868, 0.866, 0.862],
        'CHF' => [0.936, 0.940, 0.934, 0.937, 0.935, 0.931, 0.929, 0.934, 0.930, 0.927, 0.924, 0.926, 0.929, 0.931, 0.928, 0.926, 0.924, 0.922],
        'PLN' => [4.260, 4.270, 4.250, 4.260, 4.270, 4.240, 4.230, 4.220, 4.240, 4.210, 4.190, 4.200, 4.220, 4.240, 4.250, 4.260, 4.280, 4.270],
        'RON' => [4.980, 5.010, 5.040, 5.060, 5.080, 5.090, 5.100, 5.090, 5.110, 5.120, 5.100, 5.130, 5.150, 5.140, 5.170, 5.180, 5.190, 5.200],
    ];

    /** Relative amplitude of the weekly noise by currency. */
    const NOISE = ['USD' => 0.0045, 'GBP' => 0.0030, 'CHF' => 0.0025, 'PLN' => 0.0025, 'RON' => 0.0015];

    protected int $companyId = 0;
    protected ?int $adminId = null;

    public function run(Company $company)
    {
        $this->companyId = $company->id;
        mt_srand(4601);

        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');

        if (!$this->adminId) {
            throw new \RuntimeException('ProductCostAndFxDemoSeeder needs the demo administrator (' . DatabackdDemoSeeder::ADMIN_EMAIL . ') - run DatabackdDemoSeeder first.');
        }

        $products = $this->productCosts();
        $rates = $this->exchangeRates();

        $this->command?->info("products with cost price: {$products['with']}, without: {$products['without']}; fin_fx_rates: $rates");
    }

    // ---------------------------------------------------------------------------------------------
    // Cost prices
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{with: int, without: int}
     */
    protected function productCosts(): array
    {
        $with = 0;
        $without = 0;

        foreach (self::COSTS as $sku => $cost) {
            DB::table('products')->where('company_id', $this->companyId)->where('sku', $sku)
                ->update(['cost_price' => $cost, 'updated_at' => now()]);

            $cost === null ? $without++ : $with++;
        }

        return ['with' => $with, 'without' => $without];
    }

    // ---------------------------------------------------------------------------------------------
    // Exchange rates
    // ---------------------------------------------------------------------------------------------

    protected function exchangeRates(): int
    {
        $dates = $this->rateDates();

        DB::table('fin_fx_rates')
            ->where('company_id', $this->companyId)->where('base_currency', 'EUR')
            ->whereIn('currency', self::FX_CURRENCIES)
            ->whereBetween('rate_date', [self::FX_START, self::FX_END])
            ->where('created_by', $this->adminId)
            ->delete();

        $now = now()->toDateTimeString();
        $rows = [];

        foreach (self::FX_CURRENCIES as $currency) {
            $noise = 0.0;

            foreach ($dates as $date) {
                if ($currency === 'BGN') {
                    $rate = self::BGN_RATE;
                    $source = $date >= self::BGN_EURO_ADOPTION ? 'manual' : 'ecb';
                }
                else {
                    $noise = 0.6 * $noise + (mt_rand(-1000, 1000) / 1000) * self::NOISE[$currency];
                    $rate = round($this->anchorValue($currency, $date) * (1 + $noise), 4);
                    $source = in_array($date, self::MANUAL_DATES, true) ? 'manual' : 'ecb';
                }

                $rows[] = [
                    'company_id' => $this->companyId,
                    'rate_date' => $date,
                    'base_currency' => 'EUR',
                    'currency' => $currency,
                    'rate' => number_format($rate, 8, '.', ''),
                    'source' => $source,
                    'created_by' => $this->adminId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        foreach (array_chunk($rows, 300) as $chunk) {
            DB::table('fin_fx_rates')->insertOrIgnore($chunk);
        }

        return DB::table('fin_fx_rates')
            ->where('company_id', $this->companyId)->where('created_by', $this->adminId)->where('base_currency', 'EUR')
            ->whereBetween('rate_date', [self::FX_START, self::FX_END])->count();
    }

    /**
     * Rate dates: every Friday, the last business day of every month, the quarter ends and the last day (demo "today").
     *
     * @return string[] sorted Y-m-d
     */
    protected function rateDates(): array
    {
        $start = Carbon::parse(self::FX_START);
        $end = Carbon::parse(self::FX_END);
        $dates = [];

        for ($d = $start->copy(); $d->lte($end); $d->addDay()) {
            if ($d->isFriday()) {
                $dates[$d->toDateString()] = true;
            }
        }

        for ($m = $start->copy()->startOfMonth(); $m->lte($end); $m->addMonth()) {
            $last = $m->copy()->endOfMonth()->startOfDay();

            while ($last->isWeekend()) {
                $last->subDay();
            }

            if ($last->lte($end)) {
                $dates[$last->toDateString()] = true;
            }
        }

        $dates[$end->toDateString()] = true;
        ksort($dates);

        return array_keys($dates);
    }

    /** Linear interpolation between the monthly anchors (the anchor stands for the 15th of its month). */
    protected function anchorValue(string $currency, string $date): float
    {
        $anchors = self::ANCHORS[$currency];
        $day = Carbon::parse($date);
        $first = Carbon::parse(self::FX_START)->startOfMonth();

        $fromAnchor = $day->day >= 15
            ? $day->copy()->startOfMonth()
            : $day->copy()->startOfMonth()->subMonthNoOverflow();

        $i = (int)round($first->diffInMonths($fromAnchor, false));
        $i = max(0, min(count($anchors) - 2, $i));
        $anchorDate = $first->copy()->addMonths($i)->day(15);
        $nextDate = $first->copy()->addMonths($i + 1)->day(15);
        $span = max(1, $anchorDate->diffInDays($nextDate));
        $t = max(0.0, min(1.0, $anchorDate->diffInDays($day, false) / $span));

        return $anchors[$i] + ($anchors[$i + 1] - $anchors[$i]) * $t;
    }

}

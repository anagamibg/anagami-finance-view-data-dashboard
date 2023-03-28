<?php

namespace App\Services\Databackd;

use App\Models\Databackd\FinAccount;
use App\Scopes\CompanyScope;

/**
 * Simplified chart of accounts based on the Bulgarian national accounting standards (classes 1-7).
 */
class ChartOfAccounts
{

    /** code => [name (bg), type, subtype] */
    const ACCOUNTS = [
        '101' => ['Основен капитал', 'equity', null],
        '121' => ['Неразпределена печалба от минали години', 'equity', null],
        '151' => ['Дългосрочни банкови заеми', 'liability', 'non_current_liability'],
        '201' => ['Земи и терени', 'asset', 'non_current_asset'],
        '203' => ['Сгради и конструкции', 'asset', 'non_current_asset'],
        '204' => ['Машини, съоръжения и оборудване', 'asset', 'non_current_asset'],
        '241' => ['Амортизация на дълготрайни материални активи', 'asset', 'non_current_asset'],
        '302' => ['Материали', 'asset', 'inventory'],
        '304' => ['Стоки', 'asset', 'inventory'],
        '401' => ['Доставчици', 'liability', 'current_liability'],
        '411' => ['Клиенти', 'asset', 'receivable'],
        '421' => ['Персонал', 'liability', 'current_liability'],
        '453' => ['Разчети с бюджета (ДДС)', 'liability', 'current_liability'],
        '454' => ['Разчети по социално осигуряване', 'liability', 'current_liability'],
        '455' => ['Разчети с бюджета (данъци върху доходите)', 'liability', 'current_liability'],
        '501' => ['Каса', 'asset', 'cash'],
        '503' => ['Разплащателна сметка', 'asset', 'cash'],
        '601' => ['Разходи за материали', 'expense', 'cogs'],
        '602' => ['Разходи за външни услуги', 'expense', 'opex'],
        '603' => ['Разходи за амортизации', 'expense', 'depreciation'],
        '604' => ['Разходи за заплати', 'expense', 'payroll'],
        '605' => ['Разходи за осигуровки', 'expense', 'payroll'],
        '609' => ['Други разходи', 'expense', 'other'],
        '621' => ['Разходи за лихви', 'expense', 'interest'],
        '702' => ['Приходи от продажба на стоки', 'revenue', null],
        '703' => ['Приходи от продажба на услуги', 'revenue', null],
        '704' => ['Приходи от лицензи и абонаменти', 'revenue', null],
        '709' => ['Други приходи', 'revenue', 'other'],
        '721' => ['Приходи от лихви', 'revenue', 'interest'],
    ];

    /**
     * Create the chart of accounts for a company. Idempotent: only missing codes are added.
     *
     * @return int number of accounts created
     */
    public static function ensureFor(int $companyId): int
    {
        $existing = FinAccount::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $companyId)->pluck('code')->all();

        $created = 0;

        foreach (self::ACCOUNTS as $code => [$name, $type, $subtype]) {
            if (in_array((string)$code, $existing, true)) {
                continue;
            }

            FinAccount::withoutGlobalScope(CompanyScope::class)->create([
                'company_id' => $companyId,
                'code' => (string)$code,
                'name' => $name,
                'type' => $type,
                'subtype' => $subtype,
                'is_active' => true,
            ]);
            $created++;
        }

        return $created;
    }

}

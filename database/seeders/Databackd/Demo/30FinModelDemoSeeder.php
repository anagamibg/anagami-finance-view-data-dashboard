<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\ClientDetails;
use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use App\Services\Databackd\ChartOfAccounts;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo financial model: 3 legal entities, analysis dimensions, 18 months (May 2025 - Oct 2026) of
 * flow entries (revenue / expense by region, segment, product group, cost center), month-end balance
 * entries (assets = liabilities + equity per entity) and intra-group transactions for consolidation.
 * Idempotent: entities / dimensions are upserted by name (stable ids), demo entries are rebuilt.
 */
class FinModelDemoSeeder extends Seeder
{

    const MONTHS = 18;
    const START = '2025-05-01';

    const MONTH_NAMES = ['', 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'];

    /** Seasonality by calendar month (average ~ 1.0). */
    const SEASON = [1 => 0.80, 2 => 0.90, 3 => 1.06, 4 => 1.02, 5 => 1.00, 6 => 1.10, 7 => 0.98, 8 => 0.86, 9 => 1.04, 10 => 1.08, 11 => 1.05, 12 => 1.12];

    const REGIONS = [
        'SOF' => 'София', 'PLD' => 'Пловдив', 'VAR' => 'Варна', 'BGS' => 'Бургас',
        'DE' => 'ЕС — Германия', 'RO' => 'ЕС — Румъния',
    ];

    const SEGMENTS = ['MICRO' => 'Микро', 'SMALL' => 'Малки', 'MEDIUM' => 'Средни', 'PUBLIC' => 'Публичен сектор'];

    const PRODUCT_GROUPS = [
        'ACC' => 'Счетоводни услуги', 'FA' => 'Финансов анализ', 'CONS' => 'Консултации',
        'LIC' => 'Софтуерни лицензи', 'TRN' => 'Обучения',
    ];

    const COST_CENTERS = ['ADM' => 'Администрация', 'SAL' => 'Продажби', 'TEAM' => 'Екипи'];

    /** Legal entities: key => [name, eik, parent key, ownership %, sort]. */
    const ENTITIES = [
        'P' => ['Демо Финанс Груп АД', '200000101', null, 100, 1],
        'T' => ['Демо Търговия ЕООД', '200000102', 'P', 100, 2],
        'C' => ['Демо Консулт ООД', '200000103', 'P', 70, 3],
    ];

    /** Per entity parameters. */
    const PARAMS = [
        'P' => [
            'r0' => 80000,
            'groups' => ['ACC' => .28, 'FA' => .24, 'CONS' => .22, 'LIC' => .14, 'TRN' => .12],
            'accounts' => ['ACC' => '703', 'FA' => '703', 'CONS' => '703', 'LIC' => '704', 'TRN' => '703'],
            'regions' => ['SOF' => .36, 'PLD' => .14, 'VAR' => .12, 'BGS' => .09, 'DE' => .17, 'RO' => .12],
            // share of baseline revenue: code => [share, variable with the season]
            'costs' => ['604' => [.36, 0], '605' => [.068, 0], '602' => [.17, 1], '601' => [.14, 1], '603' => [.032, 0], '609' => [.035, 0], '621' => [.015, 0]],
            'capital' => 300000, 'retained' => 80000, 'loan' => [120000, 2000],
            'land' => 60000, 'buildings' => 220000, 'equipment' => 90000, 'equipment_add' => [10, 15000],
            'deprec_open' => 85000, 'k302' => 0.8, 'k304' => 0.5,
        ],
        'T' => [
            'r0' => 45000,
            'groups' => ['ACC' => .30, 'FA' => .08, 'CONS' => .10, 'LIC' => .32, 'TRN' => .20],
            'accounts' => ['ACC' => '703', 'FA' => '703', 'CONS' => '703', 'LIC' => '702', 'TRN' => '703'],
            'regions' => ['SOF' => .30, 'PLD' => .20, 'VAR' => .18, 'BGS' => .14, 'DE' => .10, 'RO' => .08],
            'costs' => ['604' => [.22, 0], '605' => [.042, 0], '602' => [.14, 1], '601' => [.34, 1], '603' => [.025, 0], '609' => [.03, 0], '621' => [.015, 0]],
            'capital' => 120000, 'retained' => 30000, 'loan' => [40000, 1000],
            'land' => 0, 'buildings' => 0, 'equipment' => 70000, 'equipment_add' => null,
            'deprec_open' => 10000, 'k302' => 0.4, 'k304' => 1.5,
        ],
        'C' => [
            'r0' => 28000,
            'groups' => ['ACC' => .05, 'FA' => .32, 'CONS' => .43, 'LIC' => .05, 'TRN' => .15],
            'accounts' => ['ACC' => '703', 'FA' => '703', 'CONS' => '703', 'LIC' => '704', 'TRN' => '703'],
            'regions' => ['SOF' => .30, 'PLD' => .10, 'VAR' => .08, 'BGS' => .07, 'DE' => .27, 'RO' => .18],
            'costs' => ['604' => [.47, 0], '605' => [.088, 0], '602' => [.14, 1], '601' => [.05, 1], '603' => [.025, 0], '609' => [.032, 0], '621' => [.012, 0]],
            'capital' => 80000, 'retained' => 40000, 'loan' => [25000, 500],
            'land' => 0, 'buildings' => 0, 'equipment' => 45000, 'equipment_add' => null,
            'deprec_open' => 6000, 'k302' => 0.3, 'k304' => 0,
        ],
    ];

    const SEGMENT_WEIGHTS = ['MICRO' => .20, 'SMALL' => .35, 'MEDIUM' => .30, 'PUBLIC' => .15];

    const SUPPLIERS = [
        'Софтуерни Услуги Облак ООД', 'Телеком Свързаност ЕООД', 'Офис Център Възраждане ООД', 'Кабинет Одит и Право ЕООД',
        'Бизнес Кънсълт Рекламна Агенция ЕООД', 'Печатница Пирин АД', 'Хотелиер Конгрес Сървиз ООД', 'Куриер Експрес България ЕООД',
        'Енерго Сървиз Юг ЕООД', 'Хардуер Партньор ООД', 'Канцеларски Свят ЕООД', 'Транспортна Компания Родопи АД',
    ];

    const CUSTOMERS_BG = [
        'Алфа Билд ООД', 'Зелена Градина ЕООД', 'Мегастор Ритейл АД', 'Тихия Дунав ЕООД', 'Синя Лагуна Хотели ООД',
        'Златна Нива Агро ЕООД', 'Бирена Фабрика Севлиево АД', 'Градски Транспорт Бургас ЕАД', 'Общинска Болница Пловдив ЕАД',
        'Стар Мост Строителство ООД', 'Планинска Аптека ЕООД', 'Технопарк Север ООД',
    ];

    const CUSTOMERS_DE = ['Берлин Бизнес Консулт ГмбХ', 'Мюнхен Финанс Партнер ГмбХ', 'Хамбург Трейдинг ГмбХ', 'Щутгарт Индъстри ГмбХ'];

    const CUSTOMERS_RO = ['Букурещ Експрес СРЛ', 'Клуж Софтуер СРЛ', 'Тимишоара Логистик СРЛ'];

    const SERVICE_NAMES = [
        'ACC' => 'Счетоводно обслужване', 'FA' => 'Финансов анализ и отчет', 'CONS' => 'Консултантски услуги',
        'LIC' => 'Софтуерен лиценз Anagami Finance View', 'TRN' => 'Обучение',
    ];

    /** @var array<int, array> rows waiting for insert */
    protected array $rows = [];

    /** @var array<string, array<int, array<string, int>>> entity => month index => account code => cents */
    protected array $sums = [];

    protected array $acc = [];
    protected array $dim = [];
    protected array $ent = [];
    protected array $clients = [];
    protected ?int $adminId = null;
    protected array $docSeq = [];
    protected int $companyId = 0;

    public function run(Company $company)
    {
        $this->companyId = $company->id;
        $this->rows = [];
        $this->sums = [];
        $this->docSeq = [];

        ChartOfAccounts::ensureFor($this->companyId);

        $this->acc = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');
        $this->clients = ClientDetails::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->pluck('company_name', 'user_id')->all();

        $this->upsertEntities();
        $this->upsertDimensions();

        // Rebuild: remove previous demo entries of the company
        FinEntry::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('source', 'demo')->delete();

        $start = Carbon::parse(self::START);
        $cum = ['P' => 0, 'T' => 0, 'C' => 0]; // cumulative profit in cents
        $cumDeprec = ['P' => 0, 'T' => 0, 'C' => 0];

        for ($i = 0; $i < self::MONTHS; $i++) {
            $month = $start->copy()->addMonths($i);

            foreach (array_keys(self::ENTITIES) as $key) {
                $this->sums[$key][$i] = [];
                $this->entityMonth($key, $i, $month);
            }

            $this->intraGroup($i, $month);

            foreach (array_keys(self::ENTITIES) as $key) {
                $this->balances($key, $i, $month, $cum[$key], $cumDeprec[$key]);
            }
        }

        foreach (array_chunk($this->rows, 500) as $chunk) {
            DB::table('fin_entries')->insert($chunk);
        }

        if ($this->command) {
            $this->command->info('fin_entries created: ' . count($this->rows));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Reference data
    // ---------------------------------------------------------------------------------------------

    protected function upsertEntities(): void
    {
        foreach (self::ENTITIES as $key => [$name, $eik, $parentKey, $pct, $sort]) {
            $entity = FinEntity::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->companyId)->where('name', $name)->first() ?? new FinEntity();

            $entity->company_id = $this->companyId;
            $entity->name = $name;
            $entity->eik = $eik;
            $entity->country = 'BG';
            $entity->currency = 'EUR';
            $entity->parent_id = $parentKey ? $this->ent[$parentKey]['id'] : null;
            $entity->ownership_pct = $pct;
            $entity->is_active = true;
            $entity->sort = $sort;
            $entity->save();

            $this->ent[$key] = ['id' => $entity->id, 'name' => $name];
        }
    }

    protected function upsertDimensions(): void
    {
        $sets = [
            'region' => self::REGIONS, 'client_segment' => self::SEGMENTS,
            'product_group' => self::PRODUCT_GROUPS, 'cost_center' => self::COST_CENTERS,
        ];

        foreach ($sets as $type => $items) {
            foreach ($items as $code => $name) {
                $dimension = FinDimension::withoutGlobalScope(CompanyScope::class)
                    ->where('company_id', $this->companyId)->where('type', $type)->where('name', $name)->first() ?? new FinDimension();

                $dimension->company_id = $this->companyId;
                $dimension->type = $type;
                $dimension->name = $name;
                $dimension->code = $code;
                $dimension->save();

                $this->dim[$type][$code] = $dimension->id;
            }
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Flows
    // ---------------------------------------------------------------------------------------------

    protected function entityMonth(string $key, int $i, Carbon $month): void
    {
        $p = self::PARAMS[$key];
        $season = self::SEASON[$month->month];
        $growth = pow(1.065, $i / 12);
        $r0 = $p['r0'] * $growth;
        $revenue = $r0 * $season * (1 + (mt_rand(-30, 30) / 1000));
        $label = self::MONTH_NAMES[$month->month] . ' ' . $month->year;
        $last = $month->daysInMonth;

        // Revenue by product group, each split in several dimension combinations
        foreach ($p['groups'] as $group => $weight) {
            $groupAmount = $revenue * $weight * (1 + (mt_rand(-80, 80) / 1000));
            $code = $p['accounts'][$group];
            $parts = $this->shares(mt_rand(4, 7));

            foreach ($parts as $share) {
                $region = $this->pick($p['regions']);
                $segment = $this->pick(self::SEGMENT_WEIGHTS);
                [$clientId, $counterparty] = $this->customer($region);

                $this->flow($key, $i, $code, $month->copy()->day(mt_rand(1, $last)), $groupAmount * $share, [
                    'region_id' => $this->dim['region'][$region],
                    'client_segment_id' => $this->dim['client_segment'][$segment],
                    'product_group_id' => $this->dim['product_group'][$group],
                    'client_id' => $clientId,
                    'counterparty_name' => $counterparty,
                    'document_no' => $this->doc($key, 'ФВ', $month->year),
                    'description' => ($code === '702' ? 'Продажба на стоки – ' : '') . self::SERVICE_NAMES[$group] . ', ' . $label,
                ]);
            }
        }

        // Other revenue and interest income
        $this->flow($key, $i, '709', $month->copy()->day(mt_rand(5, $last)), $p['r0'] * $growth * 0.006 * (1 + mt_rand(-300, 300) / 1000), [
            'region_id' => $this->dim['region']['SOF'], 'product_group_id' => $this->dim['product_group']['CONS'],
            'counterparty_name' => 'Различни контрагенти', 'document_no' => $this->doc($key, 'ФВ', $month->year),
            'description' => 'Други приходи (префактурирани разходи, неустойки), ' . $label,
        ]);
        $this->flow($key, $i, '721', $month->copy()->day($last), $p['r0'] * $growth * 0.0015 * (1 + mt_rand(-200, 200) / 1000), [
            'counterparty_name' => 'Банка Балканкредит АД', 'description' => 'Лихви по разплащателна сметка, ' . $label,
        ]);

        // Expenses
        $vf = pow($season, 0.6);

        foreach ($p['costs'] as $code => [$share, $variable]) {
            $amount = $r0 * $share * ($variable ? $vf : 1) * (1 + mt_rand(-25, 25) / 1000);

            switch ($code) {
                case '604':
                    $this->costSplit($key, $i, $code, $month, $amount, $month->copy()->day(min(25, $last)), 'ЗП', 'Работни заплати – ' . $label, null);
                    break;
                case '605':
                    $this->costSplit($key, $i, $code, $month, $amount, $month->copy()->day(min(27, $last)), 'ОС', 'Осигурителни вноски – ' . $label, 'НАП / НОИ');
                    break;
                case '602':
                    $this->supplierCosts($key, $i, $code, $month, $amount, mt_rand(3, 5), 'Външни услуги');
                    break;
                case '601':
                    $this->supplierCosts($key, $i, $code, $month, $amount, mt_rand(2, 4), 'Материали и консумативи');
                    break;
                case '603':
                    $this->flow($key, $i, $code, $month->copy()->day($last), $amount, [
                        'cost_center_id' => $this->dim['cost_center']['ADM'], 'document_no' => $this->doc($key, 'АМ', $month->year),
                        'description' => 'Амортизация на дълготрайни активи – ' . $label,
                    ]);
                    break;
                case '609':
                    foreach ([['Такси, представителни и командировъчни разходи', 'SAL', .55], ['Банкови такси и общи разходи', 'ADM', .45]] as [$text, $cc, $w]) {
                        $this->flow($key, $i, $code, $month->copy()->day(mt_rand(3, $last)), $amount * $w, [
                            'cost_center_id' => $this->dim['cost_center'][$cc], 'document_no' => $this->doc($key, 'ВХ', $month->year),
                            'counterparty_name' => self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)],
                            'description' => $text . ', ' . $label,
                        ]);
                    }
                    break;
                case '621':
                    $this->flow($key, $i, $code, $month->copy()->day($last), $amount, [
                        'cost_center_id' => $this->dim['cost_center']['ADM'], 'counterparty_name' => 'Банка Балканкредит АД',
                        'document_no' => $this->doc($key, 'БН', $month->year), 'description' => 'Лихви по инвестиционен кредит – ' . $label,
                    ]);
                    break;
            }
        }

        // One weak month: a one-off write-off of doubtful receivables turns January 2026 into a loss
        if ($month->format('Y-m') === '2026-01') {
            $this->flow($key, $i, '609', $month->copy()->day($last), $r0 * 0.10, [
                'cost_center_id' => $this->dim['cost_center']['ADM'], 'document_no' => $this->doc($key, 'ВХ', $month->year),
                'description' => 'Еднократен разход – обезценка на несъбираеми вземания',
            ]);
        }
    }

    protected function costSplit(string $key, int $i, string $code, Carbon $month, float $amount, Carbon $date, string $prefix, string $description, ?string $counterparty): void
    {
        foreach (['TEAM' => .68, 'SAL' => .14, 'ADM' => .18] as $cc => $w) {
            $this->flow($key, $i, $code, $date, $amount * $w, [
                'cost_center_id' => $this->dim['cost_center'][$cc], 'counterparty_name' => $counterparty,
                'document_no' => $prefix . '-' . $month->format('Y-m'), 'description' => $description,
            ]);
        }
    }

    protected function supplierCosts(string $key, int $i, string $code, Carbon $month, float $amount, int $count, string $text): void
    {
        $label = self::MONTH_NAMES[$month->month] . ' ' . $month->year;

        foreach ($this->shares($count) as $share) {
            $cc = $this->pick(['TEAM' => .4, 'ADM' => .35, 'SAL' => .25]);

            $this->flow($key, $i, $code, $month->copy()->day(mt_rand(1, $month->daysInMonth)), $amount * $share, [
                'cost_center_id' => $this->dim['cost_center'][$cc],
                'counterparty_name' => self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)],
                'document_no' => $this->doc($key, 'ВХ', $month->year), 'description' => $text . ', ' . $label,
            ]);
        }
    }

    /**
     * Intra-group sales: revenue 703 at the seller, expense 602 at the buyer, same date and amount.
     */
    protected function intraGroup(int $i, Carbon $month): void
    {
        $pairs = [['P', 'T', 2], ['P', 'C', 2], ['T', 'P', 2], ['C', 'P', 3], ['T', 'C', 1]];
        $weights = [];
        foreach ($pairs as $idx => $pair) {
            $weights[$idx] = $pair[2];
        }

        $services = ['CONS' => 'Консултантски услуги', 'FA' => 'Финансов анализ', 'ACC' => 'Счетоводно обслужване'];

        for ($n = mt_rand(6, 10); $n > 0; $n--) {
            [$seller, $buyer] = $pairs[$this->pick($weights)];
            $cents = mt_rand(40000, 300000);
            $date = $month->copy()->day(mt_rand(1, $month->daysInMonth));
            $group = $this->pick(['CONS' => .5, 'FA' => .3, 'ACC' => .2]);
            $this->docSeq['VG'] = ($this->docSeq['VG'] ?? 0) + 1;
            $doc = 'ВГ-' . $month->year . '-' . str_pad((string)$this->docSeq['VG'], 4, '0', STR_PAD_LEFT);
            $text = 'Вътрешногрупова сделка';

            $this->flowCents($seller, $i, '703', $date, $cents, [
                'region_id' => $this->dim['region']['SOF'], 'client_segment_id' => $this->dim['client_segment']['MEDIUM'],
                'product_group_id' => $this->dim['product_group'][$group],
                'counterparty_name' => $this->ent[$buyer]['name'], 'document_no' => $doc,
                'description' => $text . ' – ' . $services[$group],
            ]);
            $this->flowCents($buyer, $i, '602', $date, $cents, [
                'cost_center_id' => $this->dim['cost_center']['ADM'],
                'counterparty_name' => $this->ent[$seller]['name'], 'document_no' => $doc,
                'description' => $text . ' – ' . $services[$group],
            ]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Balances
    // ---------------------------------------------------------------------------------------------

    protected function balances(string $key, int $i, Carbon $month, int &$cum, int &$cumDeprec): void
    {
        $p = self::PARAMS[$key];
        $s = $this->sums[$key][$i];
        $get = fn(string $code) => $s[$code] ?? 0;

        $revenue = 0;
        foreach (['702', '703', '704', '709', '721'] as $code) {
            $revenue += $get($code);
        }
        $expense = 0;
        foreach (['601', '602', '603', '604', '605', '609', '621'] as $code) {
            $expense += $get($code);
        }

        $cum += $revenue - $expense;
        $cumDeprec += $get('603');
        $date = $month->copy()->endOfMonth();
        $c = fn(float $x) => (int)round($x * 100);

        $equity101 = $c($p['capital']);
        $equity121 = $c($p['retained']) + $cum;

        if ($equity121 <= 0) {
            throw new \RuntimeException("Demo retained earnings of entity $key are not positive in month $i");
        }

        $loan = $c(max($p['loan'][0] - $p['loan'][1] * $i, 0));
        $payables = (int)round(0.9 * ($get('601') + $get('602')));
        $personnel = (int)round(0.55 * $get('604'));
        $vat = max((int)round(0.17 * ($revenue - $get('601') - $get('602'))), 50000);
        $social = $get('605');
        $incomeTax = (int)round(0.17 * $get('604'));

        $equipment = $c($p['equipment'] + ($p['equipment_add'] && $i >= $p['equipment_add'][0] ? $p['equipment_add'][1] : 0));
        $deprec = $c($p['deprec_open']) + $cumDeprec;
        $materials = (int)round($p['k302'] * $get('601'));
        $goods = (int)round($p['k304'] * $get('601'));
        $receivables = (int)round(1.25 * $revenue);
        $cashDesk = $c(mt_rand(1500, 6000));

        $liabilitiesEquity = $equity101 + $equity121 + $loan + $payables + $personnel + $vat + $social + $incomeTax;
        $assetsWithoutBank = $c($p['land']) + $c($p['buildings']) + $equipment - $deprec + $materials + $goods + $receivables + $cashDesk;
        $bank = $liabilitiesEquity - $assetsWithoutBank;

        if ($bank <= 0) {
            throw new \RuntimeException("Demo balance sheet of entity $key for month $i has non-positive bank balance");
        }

        $balances = [
            '101' => $equity101, '121' => $equity121, '151' => $loan,
            '201' => $c($p['land']), '203' => $c($p['buildings']), '204' => $equipment, '241' => $deprec,
            '302' => $materials, '304' => $goods, '401' => $payables, '411' => $receivables, '421' => $personnel,
            '453' => $vat, '454' => $social, '455' => $incomeTax, '501' => $cashDesk, '503' => $bank,
        ];

        foreach ($balances as $code => $cents) {
            if ($cents <= 0) {
                continue;
            }

            $this->rows[] = $this->row($key, $code, $date, 'balance', $cents, ['description' => 'Салдо към ' . $date->format('d.m.Y')]);
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    protected function flow(string $key, int $i, string $code, Carbon $date, float $amount, array $attrs): void
    {
        $this->flowCents($key, $i, $code, $date, max((int)round($amount * 100), 100), $attrs);
    }

    protected function flowCents(string $key, int $i, string $code, Carbon $date, int $cents, array $attrs): void
    {
        $this->sums[$key][$i][$code] = ($this->sums[$key][$i][$code] ?? 0) + $cents;
        $this->rows[] = $this->row($key, $code, $date, 'flow', $cents, $attrs);
    }

    protected function row(string $key, string $code, Carbon $date, string $kind, int $cents, array $attrs): array
    {
        $now = now()->toDateTimeString();

        return array_merge([
            'company_id' => $this->companyId,
            'entity_id' => $this->ent[$key]['id'],
            'account_id' => $this->acc[$code],
            'entry_date' => $date->toDateString(),
            'kind' => $kind,
            'amount' => number_format($cents / 100, 2, '.', ''),
            'currency' => 'EUR',
            'amount_base' => number_format($cents / 100, 2, '.', ''),
            'region_id' => null, 'client_segment_id' => null, 'product_group_id' => null, 'cost_center_id' => null,
            'client_id' => null, 'counterparty_name' => null, 'document_no' => null, 'description' => null,
            'source' => 'demo', 'import_batch_id' => null, 'created_by' => $this->adminId,
            'created_at' => $now, 'updated_at' => $now,
        ], $attrs);
    }

    /** Random weights summing to 1. */
    protected function shares(int $count): array
    {
        $w = [];
        for ($n = 0; $n < $count; $n++) {
            $w[] = mt_rand(40, 160);
        }
        $sum = array_sum($w);

        return array_map(fn($x) => $x / $sum, $w);
    }

    /** Weighted random key. */
    protected function pick(array $weights)
    {
        $total = array_sum($weights);
        $r = mt_rand(1, (int)round($total * 1000)) / 1000;
        $acc = 0;

        foreach ($weights as $k => $w) {
            $acc += $w;
            if ($r <= $acc) {
                return $k;
            }
        }

        return array_key_last($weights);
    }

    /** [client_id|null, counterparty name] appropriate for the region. */
    protected function customer(string $region): array
    {
        if ($region === 'DE') {
            return [null, self::CUSTOMERS_DE[mt_rand(0, count(self::CUSTOMERS_DE) - 1)]];
        }

        if ($region === 'RO') {
            return [null, self::CUSTOMERS_RO[mt_rand(0, count(self::CUSTOMERS_RO) - 1)]];
        }

        if (!empty($this->clients) && mt_rand(1, 100) <= 40) {
            $ids = array_keys($this->clients);
            $id = $ids[mt_rand(0, count($ids) - 1)];

            return [$id, $this->clients[$id]];
        }

        return [null, self::CUSTOMERS_BG[mt_rand(0, count(self::CUSTOMERS_BG) - 1)]];
    }

    protected function doc(string $key, string $prefix, int $year): string
    {
        $k = $key . $prefix . $year;
        $this->docSeq[$k] = ($this->docSeq[$k] ?? 0) + 1;

        return $prefix . '-' . $year . '-' . str_pad((string)$this->docSeq[$k], 4, '0', STR_PAD_LEFT);
    }

}

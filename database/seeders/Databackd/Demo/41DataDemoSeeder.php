<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\ClientDetails;
use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinApiToken;
use App\Models\Databackd\FinDataSource;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinImportBatch;
use App\Models\User;
use App\Scopes\ActiveScope;
use App\Scopes\CompanyScope;
use Carbon\Carbon;
use Database\Seeders\Databackd\DatabackdDemoSeeder;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\DB;

/**
 * Demo data of the "Data" package: import history (Excel / Google Sheets / API, with and without errors),
 * the entries created by those imports, two saved data sources, one API token and ~50 manual entries
 * from the last month. Only flow entries (revenue / expense) are added, so the S1 balance sheet stays intact.
 *
 * Idempotent: the demo rows of this seeder (recognised by batch file names, source names, token name and the
 * "РЪЧ-" document prefix of manual entries) are removed and rebuilt. S1 entries (source = demo) are never touched.
 *
 * Demo API token: derived from APP_KEY (see demoToken()), so no credential is stored in the repository.
 */
class DataDemoSeeder extends Seeder
{

    const TODAY = '2026-10-27';

    /** Plain API token of the demo company (only its sha256 hash is stored); derived from APP_KEY, never committed. */
    public static function demoToken(): string
    {
        return 'dbk_Demo' . substr(hash('sha256', 'afv-demo-token|' . config('app.key')), 0, 32);
    }

    const TOKEN_NAME = 'Демо ERP интеграция';

    const SHEET_URL = 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789/edit#gid=0';
    const SHEET_MARKER = 'https://docs.google.com/spreadsheets/d/1AbCdEfGhIjKlMnOpQrStUvWxYz0123456789%';
    const CSV_URL = '/databackd/templates/fin-entries-template.csv';

    const SOURCE_NAMES = ['Месечни продажби — Google Sheets', 'Демо CSV файл'];

    const BATCH_FILES = [
        'Продажби_юни_2025.xlsx',
        'Бюджет и продажби 2026',
        'Продажби_Q1_2026_стар.xlsx',
        'Продажби_Q3_2026.xlsx',
        'Разходи_септември.xlsx',
        'Продажби и разходи — октомври',
        'API: ERP интеграция',
    ];

    const REGION_WEIGHTS = ['SOF' => .36, 'PLD' => .14, 'VAR' => .12, 'BGS' => .09, 'DE' => .17, 'RO' => .12];
    const SEGMENT_WEIGHTS = ['MICRO' => .20, 'SMALL' => .35, 'MEDIUM' => .30, 'PUBLIC' => .15];
    const GROUP_WEIGHTS = ['ACC' => .28, 'FA' => .22, 'CONS' => .24, 'LIC' => .14, 'TRN' => .12];
    const ENTITY_WEIGHTS = [0 => .5, 1 => .3, 2 => .2];

    const GROUP_TEXT = [
        'ACC' => 'Абонаментна такса', 'FA' => 'Финансов анализ и отчет', 'CONS' => 'Консултантски услуги',
        'LIC' => 'Софтуерен лиценз Anagami Finance View', 'TRN' => 'Обучение',
    ];

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

    /** Expense templates: [account, description, supplier|null (random), cost center, min, max]. */
    const EXPENSES = [
        ['602', 'Наем офис', 'Офис Център Възраждане ООД', 'ADM', 900, 1500],
        ['602', 'Счетоводни и одиторски услуги', 'Кабинет Одит и Право ЕООД', 'ADM', 250, 700],
        ['602', 'Облачни услуги и хостинг', 'Софтуерни Услуги Облак ООД', 'TEAM', 120, 450],
        ['602', 'Куриерски услуги', 'Куриер Експрес България ЕООД', 'SAL', 25, 120],
        ['602', 'Интернет и мобилни услуги', 'Телеком Свързаност ЕООД', 'ADM', 60, 220],
        ['604', 'Хонорар – външен експерт', null, 'TEAM', 300, 900],
        ['604', 'Възнаграждение по граждански договор', null, 'TEAM', 250, 800],
        ['605', 'Осигуровки по граждански договори', 'НАП / НОИ', 'TEAM', 60, 220],
        ['609', 'Реклама Facebook', 'Бизнес Кънсълт Рекламна Агенция ЕООД', 'SAL', 80, 450],
        ['609', 'Реклама Google Ads', 'Бизнес Кънсълт Рекламна Агенция ЕООД', 'SAL', 80, 400],
        ['609', 'Командировка – хотел и транспорт', 'Хотелиер Конгрес Сървиз ООД', 'SAL', 120, 600],
        ['609', 'Банкови такси', 'Банка Балканкредит АД', 'ADM', 10, 55],
        ['609', 'Представителни разходи', null, 'SAL', 40, 260],
        ['601', 'Канцеларски материали', 'Канцеларски Свят ЕООД', 'ADM', 20, 140],
        ['601', 'Консумативи за офиса', 'Канцеларски Свят ЕООД', 'ADM', 30, 180],
        ['621', 'Лихви по инвестиционен кредит', 'Банка Балканкредит АД', 'ADM', 150, 480],
    ];

    protected int $companyId = 0;
    protected ?int $adminId = null;
    protected array $acc = [];
    protected array $dim = [];
    /** @var int[] entity ids: parent first */
    protected array $entityIds = [];
    protected array $clients = [];
    protected int $seq = 0;

    public function run(Company $company)
    {
        $this->companyId = $company->id;
        $this->seq = 0;

        $this->acc = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'code')->all();
        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');
        $this->clients = ClientDetails::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->whereNotNull('company_name')->pluck('company_name', 'user_id')->all();
        $this->entityIds = FinEntity::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $this->companyId)->where('is_active', true)
            ->orderByRaw('parent_id IS NOT NULL')->orderBy('sort')->orderBy('id')->pluck('id')->all();

        foreach (FinDimension::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get() as $d) {
            $this->dim[$d->type][$d->code] = $d->id;
        }

        if (!$this->adminId || !$this->entityIds || !isset($this->acc['703']) || empty($this->dim['region'])) {
            throw new \RuntimeException('DataDemoSeeder needs the S1 demo data (users, entities, accounts, dimensions) - run FinModelDemoSeeder first.');
        }

        $this->cleanup();

        DB::transaction(function () {
            $batches = $this->importBatches();
            $this->sources($batches['sheets']);
            $this->token();
            $this->manualEntries();
        });

        if ($this->command) {
            $this->command->info('Data demo: ' . FinImportBatch::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->count() . ' import batches, '
                . FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->where('source', '!=', 'demo')->count() . ' non-S1 entries.');
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Cleanup
    // ---------------------------------------------------------------------------------------------

    protected function cleanup(): void
    {
        $id = $this->companyId;

        FinDataSource::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)->whereIn('name', self::SOURCE_NAMES)->delete();
        FinApiToken::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)->where('name', self::TOKEN_NAME)->delete();

        $batchIds = FinImportBatch::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)
            ->where(function ($q) {
                $q->whereIn('file_name', self::BATCH_FILES)->orWhere('source_url', 'like', self::SHEET_MARKER);
            })->pluck('id')->all();

        if ($batchIds) {
            FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)->whereIn('import_batch_id', $batchIds)->delete();
            FinImportBatch::withoutGlobalScope(CompanyScope::class)->whereIn('id', $batchIds)->delete();
        }

        FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)
            ->where('source', 'manual')->where('document_no', 'like', 'РЪЧ-%')->delete();
    }

    // ---------------------------------------------------------------------------------------------
    // Import batches
    // ---------------------------------------------------------------------------------------------

    /**
     * @return array{sheets: FinImportBatch}
     */
    protected function importBatches(): array
    {
        $salesMapping = ['entry_date' => 0, 'account_code' => 1, 'amount' => 2, 'entity' => 3, 'region' => 4,
            'client_segment' => 5, 'product_group' => 6, 'counterparty_name' => 7, 'document_no' => 8, 'description' => 9];
        $expenseMapping = ['entry_date' => 0, 'entity' => 1, 'account_code' => 2, 'amount' => 3, 'cost_center' => 4,
            'counterparty_name' => 5, 'document_no' => 6, 'description' => 7];
        $sheetsMapping = ['entry_date' => 0, 'entity' => 1, 'account_code' => 2, 'kind' => 3, 'amount' => 4, 'region' => 5,
            'client_segment' => 6, 'product_group' => 7, 'cost_center' => 8, 'counterparty_name' => 9, 'description' => 10];

        // 1. Excel, completed (June 2025 sales)
        $this->batch('excel', 'Продажби_юни_2025.xlsx', null, '2025-07-08 10:12:00', 48, [], $salesMapping,
            'sales', '2025-06-02', '2025-06-30', 'ФВ-06');

        // 2. Google Sheets, completed with errors (January 2026 budget and sales)
        $this->batch('google_sheets', 'Бюджет и продажби 2026', self::SHEET_URL, '2026-02-10 09:41:00', 60, [
            [7, [$this->err('date_required')]],
            [19, [$this->err('amount_invalid', ['value' => 'n/a'])]],
            [34, [$this->err('entity_unknown', ['value' => 'Демо Сервиз ЕООД'])]],
            [52, [$this->err('amount_positive', ['value' => '-150'])]],
        ], $sheetsMapping, 'mixed', '2026-01-05', '2026-01-30', 'ГС-01');

        // 3. Excel, undone (no entries left)
        $this->batch('excel', 'Продажби_Q1_2026_стар.xlsx', null, '2026-05-18 15:27:00', 35, [], $salesMapping,
            'none', null, null, '', 'undone');

        // 4. Excel, completed (Q3 2026 sales, 120 rows)
        $this->batch('excel', 'Продажби_Q3_2026.xlsx', null, '2026-10-02 11:05:00', 120, [], $salesMapping,
            'sales', '2026-07-01', '2026-09-30', 'ФВ-Q3');

        // 5. Excel, completed with errors (September expenses)
        $this->batch('excel', 'Разходи_септември.xlsx', null, '2026-10-05 16:48:00', 80, [
            [12, [$this->err('account_unknown', ['value' => '612'])]],
            [23, [$this->err('date_invalid', ['value' => '31.02.2026'])]],
            [31, [$this->err('amount_required')]],
            [47, [$this->err('dimension_unknown', ['type' => __('databackd-data-common.fields.region', [], 'bg'), 'value' => 'Тракия'])]],
            [58, [$this->err('amount_invalid', ['value' => '12,5,0'])]],
            [71, [$this->err('account_unknown', ['value' => '6O2']), $this->err('amount_required')]],
        ], $expenseMapping, 'expense', '2026-09-01', '2026-09-30', 'ВХ-09');

        // 6. Google Sheets, completed (October sync)
        $sheets = $this->batch('google_sheets', 'Продажби и разходи — октомври', self::SHEET_URL, '2026-10-25 08:30:00', 45, [], $sheetsMapping,
            'mixed', '2026-10-01', '2026-10-24', 'ГС-10');

        // 7. API, completed (small)
        $this->batch('api', 'API: ERP интеграция', null, '2026-10-26 14:32:00', 12, [], null,
            'sales', '2026-10-19', '2026-10-26', 'API-10');

        return ['sheets' => $sheets];
    }

    /**
     * Create a batch with its entries.
     *
     * @param array<int, array{0:int, 1:string[]}> $errors [row number, messages]
     */
    protected function batch(string $source, string $fileName, ?string $url, string $at, int $total, array $errors, ?array $mapping,
                             string $mode, ?string $from, ?string $to, string $tag, ?string $status = null): FinImportBatch
    {
        $failed = count($errors);
        $imported = $total - $failed;

        $batch = new FinImportBatch();
        $batch->company_id = $this->companyId;
        $batch->source = $source;
        $batch->file_name = $fileName;
        $batch->source_url = $url;
        $batch->rows_total = $total;
        $batch->rows_imported = $imported;
        $batch->rows_failed = $failed;
        $batch->status = $status ?: ($failed > 0 ? 'partial' : 'completed');
        $batch->mapping = $mapping;
        $batch->errors = $failed ? array_map(fn($e) => ['row' => $e[0], 'messages' => $e[1]], $errors) : null;
        $batch->created_by = $this->adminId;
        $batch->created_at = $at;
        $batch->updated_at = $at;
        $batch->save();

        if ($mode !== 'none') {
            $rows = [];
            for ($n = 1; $n <= $imported; $n++) {
                $rows[] = $this->importedRow($mode, $source, $batch->id, $from, $to, $tag, $n, $at);
            }
            usort($rows, fn($a, $b) => strcmp($a['entry_date'], $b['entry_date']));
            foreach (array_chunk($rows, 200) as $chunk) {
                DB::table('fin_entries')->insert($chunk);
            }
        }

        return $batch;
    }

    protected function importedRow(string $mode, string $source, int $batchId, string $from, string $to, string $tag, int $n, string $createdAt): array
    {
        $kind = $mode === 'mixed' ? (mt_rand(1, 100) <= 62 ? 'sales' : 'expense') : $mode;
        $date = Carbon::parse($from)->addDays(mt_rand(0, Carbon::parse($from)->diffInDays(Carbon::parse($to))));
        $doc = $tag . '-' . str_pad((string)$n, 4, '0', STR_PAD_LEFT);

        if ($kind === 'sales') {
            $row = $this->saleRow($date, mt_rand(5000, 35000) / 100);
        }
        else {
            $row = $this->expenseRow($date, true);
        }

        $row['document_no'] = $doc;
        $row['source'] = $source;
        $row['import_batch_id'] = $batchId;
        $row['created_at'] = $createdAt;
        $row['updated_at'] = $createdAt;

        return $row;
    }

    // ---------------------------------------------------------------------------------------------
    // Sources, token
    // ---------------------------------------------------------------------------------------------

    protected function sources(FinImportBatch $sheetsBatch): void
    {
        $this->source('google_sheets', 'Месечни продажби — Google Sheets', self::SHEET_URL, '0', [
            'entry_date' => 0, 'entity' => 1, 'account_code' => 2, 'kind' => 3, 'amount' => 4, 'region' => 5,
            'client_segment' => 6, 'product_group' => 7, 'cost_center' => 8, 'counterparty_name' => 9, 'description' => 10,
        ], '2026-10-25 08:30:00', $sheetsBatch->id, '2026-03-11 10:00:00');

        $this->source('csv_url', 'Демо CSV файл', self::CSV_URL, null, [
            'entry_date' => 0, 'entity' => 1, 'account_code' => 2, 'kind' => 3, 'amount' => 4, 'currency' => 5, 'region' => 6,
            'client_segment' => 7, 'product_group' => 8, 'cost_center' => 9, 'counterparty_name' => 10, 'document_no' => 11, 'description' => 12,
        ], null, null, '2026-10-20 12:15:00');
    }

    protected function source(string $type, string $name, string $url, ?string $sheet, array $mapping, ?string $syncedAt, ?int $lastBatchId, string $createdAt): void
    {
        $source = new FinDataSource();
        $source->company_id = $this->companyId;
        $source->type = $type;
        $source->name = $name;
        $source->url = $url;
        $source->sheet = $sheet;
        $source->mapping = $mapping;
        $source->default_entity_id = $this->entityIds[0];
        $source->last_synced_at = $syncedAt ? Carbon::parse($syncedAt) : null;
        $source->last_batch_id = $lastBatchId;
        $source->created_by = $this->adminId;
        $source->created_at = $createdAt;
        $source->updated_at = $syncedAt ?: $createdAt;
        $source->save();
    }

    protected function token(): void
    {
        $token = new FinApiToken();
        $token->company_id = $this->companyId;
        $token->name = self::TOKEN_NAME;
        $token->token_hash = FinApiToken::hashToken(self::demoToken());
        $token->last_four = substr(self::demoToken(), -4);
        $token->last_used_at = '2026-10-26 14:32:00';
        $token->revoked_at = null;
        $token->created_by = $this->adminId;
        $token->created_at = '2026-09-15 09:20:00';
        $token->updated_at = '2026-10-26 14:32:00';
        $token->save();
    }

    // ---------------------------------------------------------------------------------------------
    // Manual entries
    // ---------------------------------------------------------------------------------------------

    protected function manualEntries(): void
    {
        $rows = [];

        for ($n = 0; $n < 50; $n++) {
            $date = Carbon::parse('2026-10-01')->addDays(mt_rand(0, 26));
            $isSale = $n % 20 < 11; // ~55% revenue

            $row = $isSale ? $this->saleRow($date, mt_rand(12000, 140000) / 100, true) : $this->expenseRow($date, false);
            $row['source'] = 'manual';
            $row['import_batch_id'] = null;
            $row['created_at'] = $date->copy()->setTime(mt_rand(8, 17), mt_rand(0, 59))->toDateTimeString();
            $row['updated_at'] = $row['created_at'];
            $rows[] = $row;
        }

        usort($rows, fn($a, $b) => [$a['entry_date'], $a['created_at']] <=> [$b['entry_date'], $b['created_at']]);

        foreach ($rows as $i => &$row) {
            $row['document_no'] = 'РЪЧ-2026-' . str_pad((string)($i + 1), 4, '0', STR_PAD_LEFT);
        }
        unset($row);

        DB::table('fin_entries')->insert($rows);
    }

    // ---------------------------------------------------------------------------------------------
    // Row builders
    // ---------------------------------------------------------------------------------------------

    protected function saleRow(Carbon $date, float $amount, bool $manual = false): array
    {
        $entityIdx = $this->pick(array_slice(self::ENTITY_WEIGHTS, 0, count($this->entityIds), true));
        $group = $this->pick(self::GROUP_WEIGHTS);
        $region = $this->pick(self::REGION_WEIGHTS);
        $segment = $this->pick(self::SEGMENT_WEIGHTS);
        [$clientId, $counterparty] = $this->customer($region);

        // The same account logic as the S1 model: licences are 704 (parent / consulting entity) or 702 (trade entity)
        $code = $group === 'LIC' ? ($entityIdx === 1 ? '702' : '704') : '703';
        $text = self::GROUP_TEXT[$group];

        if ($manual) {
            $text = match ($group) {
                'ACC' => 'Абонаментна такса октомври',
                'FA' => 'Финансов анализ – отчет за септември',
                'CONS' => 'Консултантски услуги',
                'LIC' => 'Софтуерен лиценз Anagami Finance View – годишен',
                'TRN' => 'Обучение по финансов анализ',
            };
        }
        else {
            $text .= ', ' . $date->format('m.Y');
        }

        if ($code === '702') {
            $text = 'Продажба на лиценз – ' . $text;
        }

        return $this->row($entityIdx, $code, $date, $amount, [
            'region_id' => $this->dim['region'][$region] ?? null,
            'client_segment_id' => $this->dim['client_segment'][$segment] ?? null,
            'product_group_id' => $this->dim['product_group'][$group] ?? null,
            'client_id' => $clientId,
            'counterparty_name' => $counterparty,
            'description' => $text,
        ]);
    }

    protected function expenseRow(Carbon $date, bool $imported): array
    {
        $tpl = self::EXPENSES[mt_rand(0, count(self::EXPENSES) - 1)];
        [$code, $text, $supplier, $cc, $min, $max] = $tpl;

        // keep the one-off fixed costs (rent, interest) out of the many imported lines
        if ($imported && in_array($code, ['621'], true)) {
            $tpl = self::EXPENSES[2];
            [$code, $text, $supplier, $cc, $min, $max] = $tpl;
        }

        $entityIdx = mt_rand(1, 100) <= 55 ? 0 : (mt_rand(1, 100) <= 60 && count($this->entityIds) > 1 ? 1 : count($this->entityIds) - 1);
        $amount = mt_rand($min * 100, $max * 100) / 100;
        $month = ['', 'януари', 'февруари', 'март', 'април', 'май', 'юни', 'юли', 'август', 'септември', 'октомври', 'ноември', 'декември'][$date->month];

        if (in_array($code, ['602', '604', '605', '621'], true) && !str_starts_with($text, 'Хонорар') && !str_starts_with($text, 'Облачни') && !str_starts_with($text, 'Куриер')) {
            $text .= ' – ' . $month;
        }

        return $this->row($entityIdx, $code, $date, $amount, [
            'cost_center_id' => $this->dim['cost_center'][$cc] ?? null,
            'counterparty_name' => $supplier ?? self::SUPPLIERS[mt_rand(0, count(self::SUPPLIERS) - 1)],
            'description' => $text,
        ]);
    }

    protected function row(int $entityIdx, string $code, Carbon $date, float $amount, array $attrs): array
    {
        $entityIdx = min($entityIdx, count($this->entityIds) - 1);
        $amount = number_format($amount, 2, '.', '');
        $now = now()->toDateTimeString();

        return array_merge([
            'company_id' => $this->companyId,
            'entity_id' => $this->entityIds[$entityIdx],
            'account_id' => $this->acc[$code],
            'entry_date' => $date->toDateString(),
            'kind' => 'flow',
            'amount' => $amount,
            'currency' => 'EUR',
            'amount_base' => $amount,
            'region_id' => null, 'client_segment_id' => null, 'product_group_id' => null, 'cost_center_id' => null,
            'client_id' => null, 'counterparty_name' => null, 'document_no' => null, 'description' => null,
            'source' => 'manual', 'import_batch_id' => null, 'created_by' => $this->adminId,
            'created_at' => $now, 'updated_at' => $now,
        ], $attrs);
    }

    /** Error message in Bulgarian, in the same format as FinEntryImporter. */
    protected function err(string $key, array $replace = []): string
    {
        return __('databackd-data-common.errors.' . $key, $replace, 'bg');
    }

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

        if (!empty($this->clients) && mt_rand(1, 100) <= 45) {
            $ids = array_keys($this->clients);
            $id = $ids[mt_rand(0, count($ids) - 1)];

            return [$id, $this->clients[$id]];
        }

        return [null, self::CUSTOMERS_BG[mt_rand(0, count(self::CUSTOMERS_BG) - 1)]];
    }

}

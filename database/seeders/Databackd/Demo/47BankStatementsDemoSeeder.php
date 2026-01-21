<?php

namespace Database\Seeders\Databackd\Demo;

use App\Models\Company;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinBankStatement;
use App\Models\Databackd\FinCounterpartyMapping;
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
use Illuminate\Support\Facades\File;

/**
 * Demo data of the bank-statement import (B1a): two statements of the main current account (УниКредит Булбанк) of
 * "Демо Финанс Груп АД" - September 2026 in CAMT.053 and 1-26 October 2026 in MT940 (the Oct opening balance is the Sep
 * closing balance) - with 26 + 27 transactions booked by the counterparty rules (fin_counterparty_mappings).
 *
 * Records: fin_import_batches (source camt053 / mt940, status completed), fin_bank_statements (header with IBAN,
 * balances, period), fin_entries (source = bank_statement, kind = flow, entity = the parent company) and
 * fin_counterparty_mappings (29 rules). The same two statements are written as real files for a live import demo:
 *   storage/app/databackd-demo/bank/demo-statement.sta  (MT940, October)
 *   storage/app/databackd-demo/bank/demo-statement.xml  (CAMT.053, September)
 *
 * How the turnovers are NOT doubled: the S1/S2 model books revenue and expenses by invoice (documents ФВ-/ВХ-/РЪЧ-...).
 * The bank transactions here are separate, additional operations: advances, one-off services, trainings, licences
 * and supplier payments with their own references (UC<date><n>); no document, counterparty+amount pair or date of
 * the model is repeated. Their size is small (~10% of the parent company turnover) and the ratio of the expenses to the
 * revenues of every statement equals the profit margin the parent company already has in that month (Sep 94.8%,
 * Oct 83.5%), so the margins, the budget deviations and the ratios keep their shape.
 *
 * No balance entries are written: account 503 already has a month-end balance entry per legal entity, the balance sheet
 * of every date is "latest balance <= date", so one more 503 balance would break assets = liabilities + equity
 * between the closing date of the statement and the month end. The closing balance of the statement is shown in the
 * statement header only.
 *
 * Idempotent: the rows are recognised by the file names of the two import batches (and the "UC26" references);
 * the counterparty rules by (pattern, direction). Nothing else is touched.
 */
class BankStatementsDemoSeeder extends Seeder
{

    const OWN_NAME = 'Демо Финанс Груп АД';
    const IBAN_FALLBACK = 'BG95UNCR70001523456789';
    const BALANCE_FALLBACK = 80589.80;
    const BANK_BIC = 'UNCRBGSF';
    const BANK_NAME = 'УниКредит Булбанк АД';

    /** File names of the two import batches (and of the statements). */
    const FILE_SEP = 'UNCR_20260901-20260930.xml';
    const FILE_OCT = 'UNCR_20261001-20261026.sta';

    const DEMO_DIR = 'app/databackd-demo/bank';

    /**
     * Counterparty rules: [pattern, match_type, direction, account code, canonical counterparty name, entity name|null].
     * Every transaction below must match exactly one of them (checked when seeding).
     */
    const RULES = [
        ['Берлин Бизнес Консулт', 'contains', 'credit', '703', 'Берлин Бизнес Консулт ГмбХ', null],
        ['Мюнхен Финанс Партнер', 'contains', 'credit', '703', 'Мюнхен Финанс Партнер ГмбХ', null],
        ['Хамбург Трейдинг', 'contains', 'credit', '703', 'Хамбург Трейдинг ГмбХ', null],
        ['Букурещ Експрес', 'contains', 'credit', '703', 'Букурещ Експрес СРЛ', null],
        ['Клуж Софтуер', 'contains', 'credit', '704', 'Клуж Софтуер СРЛ', null],
        ['Витоша Софтуер ООД', 'exact', 'credit', '704', 'Витоша Софтуер ООД', null],
        ['Алфа Билд ООД', 'exact', 'credit', '703', 'Алфа Билд ООД', null],
        ['Синя Лагуна', 'contains', 'credit', '703', 'Синя Лагуна Хотели ООД', null],
        ['Златна Нива', 'contains', 'credit', '703', 'Златна Нива Агро ЕООД', null],
        ['Градски Транспорт', 'contains', 'credit', '703', 'Градски Транспорт Бургас ЕАД', null],
        ['Мегастор Ритейл АД', 'exact', 'credit', '703', 'Мегастор Ритейл АД', null],
        ['Общинска Болница', 'contains', 'credit', '703', 'Общинска Болница Пловдив ЕАД', null],
        ['Планинска Аптека', 'contains', 'credit', '702', 'Планинска Аптека ЕООД', 'Демо Търговия ЕООД'],
        ['Банка Балканкредит', 'contains', 'credit', '721', 'Банка Балканкредит АД', null],
        ['Банка Балканкредит', 'contains', 'debit', '621', 'Банка Балканкредит АД', null],
        ['УниКредит Булбанк', 'contains', 'debit', '609', 'УниКредит Булбанк АД', null],
        ['Офис Център', 'contains', 'debit', '602', 'Офис Център Възраждане ООД', null],
        ['Софтуерни Услуги Облак', 'contains', 'debit', '602', 'Софтуерни Услуги Облак ООД', null],
        ['Телеком Свързаност', 'contains', 'debit', '602', 'Телеком Свързаност ЕООД', null],
        ['Кабинет Одит и Право', 'contains', 'debit', '602', 'Кабинет Одит и Право ЕООД', null],
        ['Куриер Експрес', 'contains', 'debit', '602', 'Куриер Експрес България ЕООД', null],
        ['Енерго Сървиз', 'contains', 'debit', '602', 'Енерго Сървиз Юг ЕООД', null],
        ['Печатница Пирин', 'contains', 'debit', '602', 'Печатница Пирин АД', null],
        ['Транспортна Компания', 'contains', 'debit', '602', 'Транспортна Компания Родопи АД', null],
        ['Канцеларски Свят', 'contains', 'debit', '601', 'Канцеларски Свят ЕООД', null],
        ['Хардуер Партньор', 'contains', 'debit', '601', 'Хардуер Партньор ООД', null],
        ['Бизнес Кънсълт Рекламна', 'contains', 'debit', '609', 'Бизнес Кънсълт Рекламна Агенция ЕООД', null],
        ['Хотелиер Конгрес', 'contains', 'debit', '609', 'Хотелиер Конгрес Сървиз ООД', null],
        ['НАП', 'contains', 'debit', '605', 'НАП / НОИ', null],
    ];

    /** Dimensions of the customers: name => [region code, client segment code]. */
    const CUSTOMERS = [
        'Берлин Бизнес Консулт ГмбХ' => ['DE', 'MEDIUM'],
        'Мюнхен Финанс Партнер ГмбХ' => ['DE', 'MEDIUM'],
        'Хамбург Трейдинг ГмбХ' => ['DE', 'SMALL'],
        'Букурещ Експрес СРЛ' => ['RO', 'SMALL'],
        'Клуж Софтуер СРЛ' => ['RO', 'MICRO'],
        'Витоша Софтуер ООД' => ['SOF', 'SMALL'],
        'Алфа Билд ООД' => ['SOF', 'MEDIUM'],
        'Синя Лагуна Хотели ООД' => ['VAR', 'SMALL'],
        'Златна Нива Агро ЕООД' => ['PLD', 'SMALL'],
        'Градски Транспорт Бургас ЕАД' => ['BGS', 'PUBLIC'],
        'Мегастор Ритейл АД' => ['SOF', 'MEDIUM'],
        'Общинска Болница Пловдив ЕАД' => ['PLD', 'PUBLIC'],
    ];

    /** German / Romanian banks of the foreign customers: name prefix => [bank code, BIC]. */
    const FOREIGN_BANKS = [
        'Берлин' => ['10070000', 'DEUTDEBE'], 'Мюнхен' => ['70070010', 'DEUTDEMM'], 'Хамбург' => ['20070000', 'DEUTDEHH'],
        'Букурещ' => ['BRDE', 'BRDEROBU'], 'Клуж' => ['BTRL', 'BTRLRO22'],
    ];

    const BG_BANKS = [['STSA', 'STSABGSF'], ['FINV', 'FINVBGSF'], ['BPBI', 'BPBIBGSF'], ['RZBB', 'RZBBBGSF'], ['UBBS', 'UBBSBGSF'], ['UNCR', 'UNCRBGSF']];

    /**
     * Transactions: [day, 'C'|'D', amount EUR, counterparty, purpose (bank text, ASCII hyphen), product group (credit) | cost centre (debit)].
     * Weekdays only. Credit = received on the account, debit = paid.
     */
    const SEPTEMBER = [
        [1, 'D', 560.00, 'Офис Център Възраждане ООД', 'Наем на зала за обучения и срещи - септември', 'ADM'],
        [2, 'C', 1300.00, 'Берлин Бизнес Консулт ГмбХ', 'Авансово плащане - проект Консолидация Q3', 'CONS'],
        [2, 'D', 298.00, 'Софтуерни Услуги Облак ООД', 'Облачна инфраструктура - допълнителен капацитет', 'TEAM'],
        [3, 'C', 860.00, 'Букурещ Експрес СРЛ', 'Финансов анализ на разходите - допълнителен отчет', 'FA'],
        [4, 'D', 87.90, 'Телеком Свързаност ЕООД', 'Резервна линия и мобилен интернет - септември', 'ADM'],
        [7, 'C', 720.00, 'Алфа Билд ООД', 'Обучение по бюджетиране за мениджъри - 3 участници', 'TRN'],
        [7, 'D', 1100.00, 'Кабинет Одит и Право ЕООД', 'Одит на междинен финансов отчет - аванс', 'ADM'],
        [8, 'D', 64.30, 'Куриер Експрес България ЕООД', 'Куриерски услуги - пратки до клиенти', 'SAL'],
        [9, 'C', 1600.00, 'Мюнхен Финанс Партнер ГмбХ', 'Оценка на инвестиционен проект - етап 1', 'CONS'],
        [10, 'C', 590.00, 'Витоша Софтуер ООД', 'Лиценз Anagami Finance View Бизнес - 10 потребители, септември', 'LIC'],
        [10, 'D', 168.40, 'Канцеларски Свят ЕООД', 'Канцеларски материали и тонери', 'ADM'],
        [11, 'D', 1090.00, 'Бизнес Кънсълт Рекламна Агенция ЕООД', 'Рекламна кампания Facebook и Google Ads - септември', 'SAL'],
        [14, 'C', 1170.00, 'Мегастор Ритейл АД', 'Консултация по данъчно планиране - 13 часа', 'CONS'],
        [15, 'D', 1044.20, 'НАП / НОИ', 'Осигуровки по граждански договори - август', 'TEAM'],
        [16, 'D', 980.00, 'Хотелиер Конгрес Сървиз ООД', 'Хотел и зала - конференция за клиенти', 'SAL'],
        [17, 'C', 1380.00, 'Хамбург Трейдинг ГмбХ', 'Аванс - годишен финансов анализ', 'FA'],
        [18, 'D', 574.10, 'Енерго Сървиз Юг ЕООД', 'Електроенергия за офис - август', 'ADM'],
        [21, 'C', 960.00, 'Златна Нива Агро ЕООД', 'Анализ на паричните потоци и коефициенти - два пакета', 'FA'],
        [21, 'D', 372.00, 'Печатница Пирин АД', 'Печат на годишни отчети за клиенти', 'SAL'],
        [23, 'D', 1710.00, 'Хардуер Партньор ООД', 'Мрежово оборудване за демо среда', 'TEAM'],
        [24, 'C', 900.00, 'Общинска Болница Пловдив ЕАД', 'Внедряване на платформата - настройка на отчети', 'CONS'],
        [24, 'D', 206.30, 'Транспортна Компания Родопи АД', 'Транспорт - клиентско обучение в Пловдив', 'TEAM'],
        [25, 'D', 22.50, 'УниКредит Булбанк АД', 'Такси за вальорни преводи и поддръжка на сметка', 'ADM'],
        [28, 'C', 650.00, 'Синя Лагуна Хотели ООД', 'Бюджетиране и прогноза за 12 месеца', 'FA'],
        [28, 'D', 740.00, 'Софтуерни Услуги Облак ООД', 'Лиценз за инструмент за визуализация - тримесечен', 'TEAM'],
        [29, 'D', 590.30, 'Телеком Свързаност ЕООД', 'Мобилни връзки и роуминг за командировки - Q3', 'ADM'],
    ];

    const OCTOBER = [
        [1, 'D', 520.00, 'Офис Център Възраждане ООД', 'Наем на зала за обучения - октомври', 'ADM'],
        [2, 'C', 1450.00, 'Берлин Бизнес Консулт ГмбХ', 'Авансово плащане - проект Консолидация Q4', 'CONS'],
        [2, 'D', 340.00, 'Софтуерни Услуги Облак ООД', 'Допълнителен облачен капацитет и архивиране', 'TEAM'],
        [5, 'C', 980.00, 'Мюнхен Финанс Партнер ГмбХ', 'Допълнителен анализ на ликвидността', 'FA'],
        [5, 'D', 96.40, 'Телеком Свързаност ЕООД', 'Резервна линия и мобилен интернет - октомври', 'ADM'],
        [6, 'D', 950.00, 'Кабинет Одит и Право ЕООД', 'Правно становище по договор за лицензиране', 'ADM'],
        [7, 'C', 590.00, 'Витоша Софтуер ООД', 'Лиценз Anagami Finance View Бизнес - 10 потребители, октомври', 'LIC'],
        [8, 'C', 1200.00, 'Алфа Билд ООД', 'Допълнителни счетоводни услуги - годишно приключване', 'ACC'],
        [8, 'D', 58.70, 'Куриер Експрес България ЕООД', 'Куриерски пратки - комплекти документи', 'SAL'],
        [9, 'D', 214.60, 'Канцеларски Свят ЕООД', 'Консумативи за печат и канцеларски материали', 'ADM'],
        [12, 'C', 1750.00, 'Синя Лагуна Хотели ООД', 'Одит на разходите по хотелите - еднократна услуга', 'CONS'],
        [12, 'D', 1250.00, 'Бизнес Кънсълт Рекламна Агенция ЕООД', 'Рекламна кампания LinkedIn и Google Ads - октомври', 'SAL'],
        [13, 'D', 880.00, 'Хотелиер Конгрес Сървиз ООД', 'Хотел и конферентна зала - клиентско събитие', 'SAL'],
        [14, 'C', 960.00, 'Букурещ Експрес СРЛ', 'Обучение по бюджетиране за мениджъри - 4 участници', 'TRN'],
        [14, 'D', 642.30, 'Енерго Сървиз Юг ЕООД', 'Електроенергия за офис - септември', 'ADM'],
        [15, 'D', 1086.40, 'НАП / НОИ', 'Осигуровки по граждански договори - септември', 'TEAM'],
        [16, 'C', 1300.00, 'Хамбург Трейдинг ГмбХ', 'Бюджетиране и прогноза - 2 дружества', 'FA'],
        [16, 'D', 1320.00, 'Хардуер Партньор ООД', 'Монитори и периферия за екипа', 'TEAM'],
        [19, 'C', 780.00, 'Златна Нива Агро ЕООД', 'Анализ на паричните потоци - 3 отчета', 'FA'],
        [19, 'D', 318.00, 'Печатница Пирин АД', 'Печат на отчетни материали и брошури', 'SAL'],
        [20, 'D', 186.50, 'Транспортна Компания Родопи АД', 'Транспорт на оборудване за обучение', 'TEAM'],
        [21, 'C', 1350.00, 'Градски Транспорт Бургас ЕАД', 'Внедряване на платформата - етап 2', 'CONS'],
        [22, 'D', 23.40, 'УниКредит Булбанк АД', 'Такси за вальорни преводи и поддръжка на сметка', 'ADM'],
        [23, 'C', 522.00, 'Клуж Софтуер СРЛ', 'Лиценз Anagami Finance View Старт - 6 потребители, 3 месеца', 'LIC'],
        [23, 'D', 940.00, 'Хотелиер Конгрес Сървиз ООД', 'Командировка - хотел и транспорт, Берлин', 'SAL'],
        [26, 'C', 900.00, 'Мегастор Ритейл АД', 'Консултация по данъчно планиране - 10 часа', 'CONS'],
        [26, 'D', 1012.00, 'Софтуерни Услуги Облак ООД', 'Лиценз за инструмент за визуализация - годишен', 'TEAM'],
    ];

    protected int $companyId = 0;
    protected ?int $adminId = null;
    protected ?int $entityId = null;
    protected string $iban = self::IBAN_FALLBACK;
    protected int $closingCents = 0;

    /** @var array<string, int> account code => id */
    protected array $acc = [];
    /** @var array<string, int> "type:code" => id */
    protected array $dim = [];
    protected array $clientIds = [];
    protected array $entityIds = [];

    public function run(Company $company)
    {
        $this->companyId = $company->id;

        $this->adminId = User::withoutGlobalScopes([CompanyScope::class, ActiveScope::class])
            ->where('company_id', $this->companyId)->where('email', DatabackdDemoSeeder::ADMIN_EMAIL)->value('id');
        $this->acc = FinAccount::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'code')->all();

        foreach (FinDimension::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->get() as $d) {
            $this->dim[$d->type . ':' . $d->code] = $d->id;
        }

        $this->entityIds = FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->pluck('id', 'name')->all();
        $this->entityId = $this->entityIds[self::OWN_NAME]
            ?? FinEntity::withoutGlobalScope(CompanyScope::class)->where('company_id', $this->companyId)->whereNull('parent_id')->orderBy('sort')->value('id');
        $this->clientIds = array_flip(DB::table('client_details')->where('company_id', $this->companyId)->whereNotNull('company_name')->pluck('company_name', 'user_id')->all());

        if (!$this->adminId || !$this->entityId || !isset($this->acc['703'], $this->acc['609']) || empty($this->dim['region:SOF'])) {
            throw new \RuntimeException('BankStatementsDemoSeeder needs the S1 demo data (administrator, legal entities, accounts, dimensions) - run FinModelDemoSeeder first.');
        }

        $this->bankAccount();
        $this->cleanup();
        $rules = $this->mappings();

        // balances: the closing balance of the October statement = the balance of the bank account in the "Bank accounts" module
        $oct = $this->prepare(self::OCTOBER, 2026, 10, 26, 'mt940', self::FILE_OCT, '00043/001', 43, '2026-10-27 09:05:00');
        $sep = $this->prepare(self::SEPTEMBER, 2026, 9, 30, 'camt053', self::FILE_SEP, '00042/001', 42, '2026-10-01 09:12:00');

        $oct['closing'] = $this->closingCents;
        $oct['opening'] = $oct['closing'] - $oct['net'];
        $sep['closing'] = $oct['opening'];
        $sep['opening'] = $sep['closing'] - $sep['net'];

        $counts = [];

        DB::transaction(function () use (&$sep, &$oct, $rules, &$counts) {
            foreach ([$sep, $oct] as $statement) {
                $counts[] = $this->store($statement, $rules);
            }
        });

        $this->writeFiles($oct, $sep);

        if ($this->command) {
            $this->command->info(sprintf(
                'bank statements: %d batches, %d fin_bank_statements, %d fin_entries (%s), %d counterparty rules; IBAN %s; Sep %s -> %s, Oct %s -> %s EUR',
                2, 2, array_sum($counts), implode(' + ', $counts), count(self::RULES), $this->iban,
                $this->money($sep['opening']), $this->money($sep['closing']), $this->money($oct['opening']), $this->money($oct['closing'])
            ));
        }
    }

    // ---------------------------------------------------------------------------------------------
    // Account, cleanup, rules
    // ---------------------------------------------------------------------------------------------

    /** IBAN and balance of the main current account of the company (module "Bank accounts"), with fallbacks. */
    protected function bankAccount(): void
    {
        $account = DB::table('bank_accounts')
            ->where('company_id', $this->companyId)->where('account_number', 'like', 'BG__UNCR%')
            ->orderBy('id')->first();

        $this->iban = $account && $this->validIban($account->account_number) ? $account->account_number : self::IBAN_FALLBACK;
        $balance = $account && (float)$account->bank_balance > 10000 ? (float)$account->bank_balance : self::BALANCE_FALLBACK;
        $this->closingCents = (int)round($balance * 100);
    }

    protected function cleanup(): void
    {
        $id = $this->companyId;
        $files = [self::FILE_SEP, self::FILE_OCT];

        $batchIds = FinImportBatch::withoutGlobalScope(CompanyScope::class)
            ->where('company_id', $id)->whereIn('source', ['mt940', 'camt053'])->whereIn('file_name', $files)->pluck('id')->all();

        FinEntry::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)->where('source', 'bank_statement')
            ->where(function ($q) use ($batchIds) {
                $q->whereIn('import_batch_id', $batchIds)
                    ->orWhere(function ($q2) {
                        $q2->whereNull('import_batch_id')->where('document_no', 'like', 'UC26%');
                    });
            })->delete();

        FinBankStatement::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)
            ->where(function ($q) use ($batchIds, $files) {
                $q->whereIn('import_batch_id', $batchIds)->orWhereIn('file_name', $files);
            })->delete();

        FinImportBatch::withoutGlobalScope(CompanyScope::class)->where('company_id', $id)->whereIn('id', $batchIds)->delete();
    }

    /**
     * @return array<int, array{pattern: string, type: string, direction: string, code: string}> the saved rules
     */
    protected function mappings(): array
    {
        $out = [];

        foreach (self::RULES as [$pattern, $type, $direction, $code, $name, $entity]) {
            FinCounterpartyMapping::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->companyId)->where('pattern', $pattern)->where('direction', $direction)->delete();

            $rule = new FinCounterpartyMapping();
            $rule->company_id = $this->companyId;
            $rule->pattern = $pattern;
            $rule->match_type = $type;
            $rule->direction = $direction;
            $rule->account_id = $this->acc[$code];
            $rule->entity_id = $entity ? ($this->entityIds[$entity] ?? null) : null;
            $rule->counterparty_name = $name;
            $rule->created_by = $this->adminId;
            $rule->created_at = '2026-10-01 09:10:00';
            $rule->updated_at = '2026-10-01 09:10:00';
            $rule->save();

            $out[] = ['pattern' => $pattern, 'type' => $type, 'direction' => $direction, 'code' => $code];
        }

        return $out;
    }

    /** Account code by the counterparty rules (first and only match); throws when a transaction is not covered. */
    protected function classify(array $rules, string $direction, string $counterparty): string
    {
        $codes = [];

        foreach ($rules as $r) {
            if ($r['direction'] !== $direction && $r['direction'] !== 'any') {
                continue;
            }

            $hit = $r['type'] === 'exact'
                ? mb_strtolower($counterparty) === mb_strtolower($r['pattern'])
                : mb_stripos($counterparty, $r['pattern']) !== false;

            if ($hit) {
                $codes[] = $r['code'];
            }
        }

        if (count($codes) !== 1) {
            throw new \RuntimeException("Bank statement demo: $direction transaction of '$counterparty' matches " . count($codes) . ' counterparty rules (expected 1).');
        }

        return $codes[0];
    }

    // ---------------------------------------------------------------------------------------------
    // Statements
    // ---------------------------------------------------------------------------------------------

    /**
     * Normalised statement: transactions with dates, cents, references and the account code (by the rules),
     * the net movement. Balances are filled in by run().
     */
    protected function prepare(array $tx, int $year, int $month, int $toDay, string $format, string $file, string $number, int $sequence, string $importedAt): array
    {
        $rules = [];

        foreach (self::RULES as [$pattern, $type, $direction, $code]) {
            $rules[] = ['pattern' => $pattern, 'type' => $type, 'direction' => $direction, 'code' => $code];
        }

        $rows = [];
        $net = 0;
        $last = 0;

        foreach ($tx as $i => [$day, $dir, $amount, $name, $purpose, $extra]) {
            $date = Carbon::create($year, $month, $day);

            if ($date->isWeekend() || $day < $last) {
                throw new \RuntimeException("Bank statement demo: transaction $i of $year-$month is on a weekend or out of order.");
            }

            $last = $day;
            $cents = (int)round($amount * 100);
            $net += $dir === 'C' ? $cents : -$cents;

            $rows[] = [
                'date' => $date->toDateString(),
                'dir' => $dir,
                'cents' => $cents,
                'name' => $name,
                'purpose' => $purpose,
                'extra' => $extra,
                'ref' => 'UC' . $date->format('ymd') . str_pad((string)($i + 1), 2, '0', STR_PAD_LEFT),
                'code' => $this->classify($rules, $dir === 'C' ? 'credit' : 'debit', $name),
            ];
        }

        $from = Carbon::create($year, $month, 1);
        $to = Carbon::create($year, $month, $toDay);

        if ($last > $toDay) {
            throw new \RuntimeException("Bank statement demo: a transaction of $year-$month is after the end of the statement period.");
        }

        return [
            'format' => $format, 'file' => $file, 'number' => $number, 'sequence' => $sequence, 'imported_at' => $importedAt,
            'from' => $from->toDateString(), 'to' => $to->toDateString(), 'tx' => $rows, 'net' => $net,
            'opening' => 0, 'closing' => 0,
        ];
    }

    /** Saves the batch, the statement header and the entries; returns the number of entries. */
    protected function store(array $s, array $rules): int
    {
        $count = count($s['tx']);

        $batch = new FinImportBatch();
        $batch->company_id = $this->companyId;
        $batch->source = $s['format'];
        $batch->file_name = $s['file'];
        $batch->source_url = null;
        $batch->rows_total = $count;
        $batch->rows_imported = $count;
        $batch->rows_failed = 0;
        $batch->status = 'completed';
        $batch->mapping = null;
        $batch->errors = null;
        $batch->created_by = $this->adminId;
        $batch->created_at = $s['imported_at'];
        $batch->updated_at = $s['imported_at'];
        $batch->save();

        $header = new FinBankStatement();
        $header->company_id = $this->companyId;
        $header->import_batch_id = $batch->id;
        $header->entity_id = $this->entityId;
        $header->format = $s['format'];
        $header->account_iban = $this->iban;
        $header->currency = 'EUR';
        $header->statement_no = $s['number'];
        $header->date_from = $s['from'];
        $header->date_to = $s['to'];
        $header->opening_balance = $s['opening'] / 100;
        $header->closing_balance = $s['closing'] / 100;
        $header->transactions_count = $count;
        $header->file_name = $s['file'];
        $header->created_by = $this->adminId;
        $header->created_at = $s['imported_at'];
        $header->updated_at = $s['imported_at'];
        $header->save();

        $rows = [];

        foreach ($s['tx'] as $t) {
            $isCredit = $t['dir'] === 'C';
            $amount = number_format($t['cents'] / 100, 2, '.', '');
            $row = [
                'company_id' => $this->companyId,
                'entity_id' => $this->entityId,
                'account_id' => $this->acc[$t['code']],
                'entry_date' => $t['date'],
                'kind' => 'flow',
                'amount' => $amount,
                'currency' => 'EUR',
                'amount_base' => $amount,
                'region_id' => null, 'client_segment_id' => null, 'product_group_id' => null, 'cost_center_id' => null,
                'client_id' => null,
                'counterparty_name' => $t['name'],
                'document_no' => $t['ref'],
                'description' => $t['purpose'],
                'source' => 'bank_statement',
                'import_batch_id' => $batch->id,
                'created_by' => $this->adminId,
                'created_at' => $s['imported_at'],
                'updated_at' => $s['imported_at'],
            ];

            if ($isCredit) {
                [$region, $segment] = self::CUSTOMERS[$t['name']] ?? ['SOF', 'SMALL'];
                $row['region_id'] = $this->dim['region:' . $region] ?? null;
                $row['client_segment_id'] = $this->dim['client_segment:' . $segment] ?? null;
                $row['product_group_id'] = $this->dim['product_group:' . $t['extra']] ?? null;
                $row['client_id'] = $this->clientIds[$t['name']] ?? null;
            }
            else {
                $row['cost_center_id'] = $this->dim['cost_center:' . $t['extra']] ?? null;
            }

            $rows[] = $row;
        }

        DB::table('fin_entries')->insert($rows);

        return $count;
    }

    // ---------------------------------------------------------------------------------------------
    // Files (for the live import demo)
    // ---------------------------------------------------------------------------------------------

    protected function writeFiles(array $oct, array $sep): void
    {
        $dir = storage_path(self::DEMO_DIR);
        File::ensureDirectoryExists($dir);

        file_put_contents($dir . '/demo-statement.sta', $this->mt940($oct));
        file_put_contents($dir . '/demo-statement.xml', $this->camt053($sep));
    }

    protected function mt940(array $s): string
    {
        $from = Carbon::parse($s['from']);
        $to = Carbon::parse($s['to']);
        $lines = [
            ':20:DFG' . $to->format('ymd') . 'UC',
            ':25:' . $this->iban,
            ':28C:' . $s['number'],
            ':60F:' . ($s['opening'] >= 0 ? 'C' : 'D') . $from->format('ymd') . 'EUR' . $this->money(abs($s['opening']), ','),
        ];

        foreach ($s['tx'] as $t) {
            $date = Carbon::parse($t['date']);
            $isFee = $t['name'] === self::BANK_NAME;
            $isCredit = $t['dir'] === 'C';

            $lines[] = ':61:' . $date->format('ymd') . $date->format('md') . $t['dir'] . $this->money($t['cents'], ',')
                . 'N' . ($isFee ? 'CHG' : 'TRF') . 'NONREF//' . $t['ref'];

            $lines[] = ':86:' . ($isFee ? '805' : ($isCredit ? '051' : '020')) . '?00' . ($isFee ? 'ТАКСА' : ($isCredit ? 'ПОСТЪПЛЕНИЕ ПО ПРЕВОД' : 'ПЛАЩАНЕ ПО ПРЕВОД'));

            foreach (array_slice($this->wrap($t['purpose'], 27), 0, 4) as $n => $chunk) {
                $lines[] = '?2' . $n . $chunk;
            }

            if (!$isFee) {
                [$iban, $bic] = $this->counterpartyAccount($t['name']);
                $lines[] = '?30' . $bic;
                $lines[] = '?31' . $iban;
            }

            foreach (array_slice($this->wrap($t['name'], 27), 0, 2) as $n => $chunk) {
                $lines[] = '?3' . ($n + 2) . $chunk;
            }
        }

        $closing = ($s['closing'] >= 0 ? 'C' : 'D') . $to->format('ymd') . 'EUR' . $this->money(abs($s['closing']), ',');
        $lines[] = ':62F:' . $closing;
        $lines[] = ':64:' . $closing;
        $lines[] = '-';

        return implode("\n", $lines) . "\n";
    }

    protected function camt053(array $s): string
    {
        $credits = array_sum(array_map(fn($t) => $t['dir'] === 'C' ? $t['cents'] : 0, $s['tx']));
        $debits = array_sum(array_map(fn($t) => $t['dir'] === 'D' ? $t['cents'] : 0, $s['tx']));
        $nCredits = count(array_filter($s['tx'], fn($t) => $t['dir'] === 'C'));
        $nDebits = count($s['tx']) - $nCredits;
        $e = fn(string $v) => htmlspecialchars($v, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $created = Carbon::parse($s['to'])->addDay()->format('Y-m-d') . 'T05:30:00';
        $net = $credits - $debits;

        $x = [];
        $x[] = '<?xml version="1.0" encoding="UTF-8"?>';
        $x[] = '<Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.02" xmlns:xsi="http://www.w3.org/2001/XMLSchema-instance">';
        $x[] = '  <BkToCstmrStmt>';
        $x[] = '    <GrpHdr>';
        $x[] = '      <MsgId>DFG-' . $e(str_replace('-', '', $s['to'])) . '-' . $s['sequence'] . '</MsgId>';
        $x[] = '      <CreDtTm>' . $created . '</CreDtTm>';
        $x[] = '    </GrpHdr>';
        $x[] = '    <Stmt>';
        $x[] = '      <Id>' . $e($this->iban . '-' . str_replace('-', '', $s['to']) . '-' . $s['sequence']) . '</Id>';
        $x[] = '      <ElctrncSeqNb>' . $s['sequence'] . '</ElctrncSeqNb>';
        $x[] = '      <LglSeqNb>1</LglSeqNb>';
        $x[] = '      <CreDtTm>' . $created . '</CreDtTm>';
        $x[] = '      <FrToDt>';
        $x[] = '        <FrDtTm>' . $s['from'] . 'T00:00:00</FrDtTm>';
        $x[] = '        <ToDtTm>' . $s['to'] . 'T23:59:59</ToDtTm>';
        $x[] = '      </FrToDt>';
        $x[] = '      <Acct>';
        $x[] = '        <Id><IBAN>' . $this->iban . '</IBAN></Id>';
        $x[] = '        <Ccy>EUR</Ccy>';
        $x[] = '        <Nm>Основна разплащателна сметка</Nm>';
        $x[] = '        <Ownr><Nm>' . $e(self::OWN_NAME) . '</Nm></Ownr>';
        $x[] = '        <Svcr><FinInstnId><BIC>' . self::BANK_BIC . '</BIC><Nm>' . $e(self::BANK_NAME) . '</Nm></FinInstnId></Svcr>';
        $x[] = '      </Acct>';

        foreach ([['OPBD', $s['opening'], $s['from']], ['CLBD', $s['closing'], $s['to']]] as [$code, $cents, $date]) {
            $x[] = '      <Bal>';
            $x[] = '        <Tp><CdOrPrtry><Cd>' . $code . '</Cd></CdOrPrtry></Tp>';
            $x[] = '        <Amt Ccy="EUR">' . $this->money(abs($cents)) . '</Amt>';
            $x[] = '        <CdtDbtInd>' . ($cents >= 0 ? 'CRDT' : 'DBIT') . '</CdtDbtInd>';
            $x[] = '        <Dt><Dt>' . $date . '</Dt></Dt>';
            $x[] = '      </Bal>';
        }

        $x[] = '      <TxsSummry>';
        $x[] = '        <TtlNtries>';
        $x[] = '          <NbOfNtries>' . count($s['tx']) . '</NbOfNtries>';
        $x[] = '          <Sum>' . $this->money($credits + $debits) . '</Sum>';
        $x[] = '          <TtlNetNtryAmt>' . $this->money(abs($net)) . '</TtlNetNtryAmt>';
        $x[] = '          <CdtDbtInd>' . ($net >= 0 ? 'CRDT' : 'DBIT') . '</CdtDbtInd>';
        $x[] = '        </TtlNtries>';
        $x[] = '        <TtlCdtNtries><NbOfNtries>' . $nCredits . '</NbOfNtries><Sum>' . $this->money($credits) . '</Sum></TtlCdtNtries>';
        $x[] = '        <TtlDbtNtries><NbOfNtries>' . $nDebits . '</NbOfNtries><Sum>' . $this->money($debits) . '</Sum></TtlDbtNtries>';
        $x[] = '      </TxsSummry>';

        foreach ($s['tx'] as $n => $t) {
            $isCredit = $t['dir'] === 'C';
            $isFee = $t['name'] === self::BANK_NAME;
            $party = $isCredit ? 'Dbtr' : 'Cdtr';
            $agent = $isCredit ? 'DbtrAgt' : 'CdtrAgt';

            $x[] = '      <Ntry>';
            $x[] = '        <NtryRef>' . ($n + 1) . '</NtryRef>';
            $x[] = '        <Amt Ccy="EUR">' . $this->money($t['cents']) . '</Amt>';
            $x[] = '        <CdtDbtInd>' . ($isCredit ? 'CRDT' : 'DBIT') . '</CdtDbtInd>';
            $x[] = '        <Sts>BOOK</Sts>';
            $x[] = '        <BookgDt><Dt>' . $t['date'] . '</Dt></BookgDt>';
            $x[] = '        <ValDt><Dt>' . $t['date'] . '</Dt></ValDt>';
            $x[] = '        <AcctSvcrRef>' . $t['ref'] . '</AcctSvcrRef>';
            $x[] = '        <BkTxCd><Domn><Cd>' . ($isFee ? 'ACMT' : 'PMNT') . '</Cd><Fmly><Cd>'
                . ($isFee ? 'MDOP' : ($isCredit ? 'RCDT' : 'ICDT')) . '</Cd><SubFmlyCd>' . ($isFee ? 'CHRG' : 'ESCT') . '</SubFmlyCd></Fmly></Domn></BkTxCd>';
            $x[] = '        <NtryDtls>';
            $x[] = '          <TxDtls>';
            $x[] = '            <Refs><EndToEndId>NOTPROVIDED</EndToEndId></Refs>';
            $x[] = '            <RltdPties>';
            $x[] = '              <' . $party . '><Nm>' . $e($t['name']) . '</Nm></' . $party . '>';

            if (!$isFee) {
                [$iban, $bic] = $this->counterpartyAccount($t['name']);
                $x[] = '              <' . $party . 'Acct><Id><IBAN>' . $iban . '</IBAN></Id></' . $party . 'Acct>';
                $x[] = '            </RltdPties>';
                $x[] = '            <RltdAgts>';
                $x[] = '              <' . $agent . '><FinInstnId><BIC>' . $bic . '</BIC></FinInstnId></' . $agent . '>';
                $x[] = '            </RltdAgts>';
            }
            else {
                $x[] = '            </RltdPties>';
            }

            $x[] = '            <RmtInf><Ustrd>' . $e($t['purpose']) . '</Ustrd></RmtInf>';
            $x[] = '          </TxDtls>';
            $x[] = '        </NtryDtls>';
            $x[] = '      </Ntry>';
        }

        $x[] = '    </Stmt>';
        $x[] = '  </BkToCstmrStmt>';
        $x[] = '</Document>';

        return implode("\n", $x) . "\n";
    }

    // ---------------------------------------------------------------------------------------------
    // Helpers
    // ---------------------------------------------------------------------------------------------

    /**
     * Splits a text into the fixed-width sub-fields of MT940 :86: (27 characters) at word boundaries; every chunk but the
     * last keeps its trailing space, so concatenating the chunks gives back the exact text.
     *
     * @return string[]
     */
    protected function wrap(string $text, int $width): array
    {
        $chunks = [];
        $current = '';

        foreach (explode(' ', $text) as $i => $word) {
            $sep = $i > 0 ? ' ' : '';

            if (mb_strlen($current . $sep . $word) <= $width) {
                $current .= $sep . $word;

                continue;
            }

            if ($current !== '') {
                if (mb_strlen($current) + 1 <= $width) {
                    $chunks[] = $current . $sep;
                    $current = $word;
                }
                else {
                    $chunks[] = $current;
                    $current = $sep . $word;
                }
            }
            else {
                $current = $word;
            }

            while (mb_strlen($current) > $width) {
                $chunks[] = mb_substr($current, 0, $width);
                $current = mb_substr($current, $width);
            }
        }

        if ($current !== '') {
            $chunks[] = $current;
        }

        return $chunks;
    }

    protected function money(int $cents, string $decimal = '.'): string
    {
        return number_format($cents / 100, 2, $decimal, '');
    }

    /**
     * Deterministic, valid (mod 97) IBAN and BIC of a counterparty: German and Romanian banks for the foreign
     * customers, the NRA (BNB) account for "НАП / НОИ", a rotating Bulgarian bank for everybody else.
     *
     * @return array{0: string, 1: string} [IBAN, BIC]
     */
    protected function counterpartyAccount(string $name): array
    {
        $digits = fn(int $len, string $salt) => str_pad(substr((string)abs(crc32($name . $salt)), 0, $len), $len, '0', STR_PAD_LEFT);
        $prefix = explode(' ', $name)[0];

        if (isset(self::FOREIGN_BANKS[$prefix])) {
            [$code, $bic] = self::FOREIGN_BANKS[$prefix];

            return str_contains($name, 'ГмбХ')
                ? [$this->makeIban('DE', $code . $digits(10, 'a')), $bic]
                : [$this->makeIban('RO', $code . $digits(16, 'b')), $bic];
        }

        if ($name === 'НАП / НОИ') {
            return [$this->makeIban('BG', 'BNBG966133' . $digits(8, 'c')), 'BNBGBGSD'];
        }

        [$code, $bic] = self::BG_BANKS[abs(crc32($name)) % count(self::BG_BANKS)];

        return [$this->makeIban('BG', $code . '9300' . '10' . $digits(8, 'd')), $bic];
    }

    protected function makeIban(string $country, string $bban): string
    {
        $mod = $this->mod97($bban . $country . '00');

        return $country . str_pad((string)(98 - $mod), 2, '0', STR_PAD_LEFT) . $bban;
    }

    protected function validIban(string $iban): bool
    {
        return strlen($iban) > 14 && $this->mod97(substr($iban, 4) . substr($iban, 0, 4)) === 1;
    }

    protected function mod97(string $s): int
    {
        $numeric = '';

        foreach (str_split($s) as $ch) {
            $numeric .= ctype_alpha($ch) ? (string)(ord(strtoupper($ch)) - 55) : $ch;
        }

        $mod = 0;

        foreach (str_split($numeric) as $digit) {
            $mod = ($mod * 10 + (int)$digit) % 97;
        }

        return $mod;
    }

}

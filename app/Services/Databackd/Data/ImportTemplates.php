<?php

namespace App\Services\Databackd\Data;

use App\Models\Databackd\FinAccount;

/**
 * AFV (S8, B1b): ready-made column-mapping templates for exports of the Bulgarian accounting programs
 * Microinvest, Ajur, Plus-Minus and Business Navigator.
 *
 * IMPORTANT: the column names are the TYPICAL ones of such exports (trial balance / chronological journal). They were not checked against real
 * exports of these programs. A column that is not found stays unmapped and is chosen by hand in the mapping step of the import wizard.
 *
 * Two layouts exist for every platform:
 *  - trial_balance (оборотна ведомост): one row per account with opening / turnover / closing debit and credit columns. There is no single "amount"
 *    column and usually no date, so the template ADDS computed columns at the end of the table: "amount for import" (P&L accounts = turnover on
 *    their natural side, balance sheet accounts = closing balance on their natural side) and, when the file has no date column, the period date
 *    given in the wizard. Rows with a zero amount and "total" rows are dropped.
 *  - journal (дневник / хронологична ведомост): one row per posting with a debit account and a credit account. fin_entries keeps one account per
 *    row, so the template adds "account for import" (the P&L side of the posting: debit of an expense account / credit of a revenue account) and
 *    "amount for import" (negative for reversals, so the importer rejects them visibly). Postings that do not touch a P&L account are dropped
 *    (balance sheet movements are not flows).
 *
 * Program reports usually start with title rows (company, period) and may have a two-row header ("Начално салдо" over "Дебит | Кредит"):
 * apply() looks for the header in the first rows (locateHeader()) and drops the rows above it. Analytic accounts ("411/1", "602.01") are
 * imported on their synthetic account of the chart; in a trial balance a row whose parent account is in the file too is dropped (the parent
 * already holds it). Closing entries (P&L to P&L, P&L to the result accounts 12x) are dropped from a journal.
 *
 * The original columns are never changed, so the mapping step still shows the whole file. Applying a template is deterministic: the wizard
 * calls apply() again with the same file when the import runs.
 *
 * It only uses public methods of FinEntryImporter (parseAmount, fields()).
 */
class ImportTemplates
{

    const PLATFORMS = ['microinvest', 'ajur', 'plus_minus', 'business_navigator'];

    const LAYOUT_TRIAL_BALANCE = 'trial_balance';
    const LAYOUT_JOURNAL = 'journal';
    const LAYOUTS = [self::LAYOUT_TRIAL_BALANCE, self::LAYOUT_JOURNAL];

    /** Example files, relative to public/. */
    const SAMPLE_DIR = 'databackd/templates/import-templates';

    /** How many rows locateHeader() looks at for the real header row (title rows of the report come before it). */
    const HEADER_SCAN_ROWS = 20;

    /** Result accounts 12x of the chart (e.g. 123 "Печалби и загуби от текущата година"): P&L accounts are closed to them at the end of the period. */
    const RESULT_ACCOUNT_PREFIX = '12';

    /** Words that start a total row ("Общо:", "Всичко за раздел 6"). \b does not work for Cyrillic without (*UCP), hence the lookahead. */
    const TOTAL_PATTERN = '/^(общо|всичко|сума|итого|total|sum)(?![\p{L}\p{N}])/iu';

    /** Roles of the original columns that must be found for the computed columns to be possible. */
    const ESSENTIAL = [
        self::LAYOUT_TRIAL_BALANCE => ['account', 'turnover_debit', 'turnover_credit', 'close_debit', 'close_credit'],
        self::LAYOUT_JOURNAL => ['date', 'debit_account', 'credit_account', 'amount'],
    ];

    /** Role => import field, used when the file is read column by column (no computed columns). */
    const DIRECT_FIELDS = [
        self::LAYOUT_TRIAL_BALANCE => ['account' => 'account_code', 'date' => 'entry_date', 'entity' => 'entity', 'currency' => 'currency'],
        self::LAYOUT_JOURNAL => ['date' => 'entry_date', 'debit_account' => 'account_code', 'amount' => 'amount', 'document' => 'document_no',
            'counterparty' => 'counterparty_name', 'description' => 'description', 'entity' => 'entity', 'currency' => 'currency'],
    ];

    protected int $companyId;
    protected FinEntryImporter $importer;

    /** @var array<string,string>|null code => account type of the company chart */
    protected ?array $chart = null;

    /** @var array<string,array>|null */
    protected ?array $templates = null;

    /** Decimal separator per column of the table being applied (FinEntryImporter::detectDecimalSeparators()) and the program's one as fallback. */
    protected array $decimals = [];
    protected ?string $decimal = null;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?: company()->id;
        $this->importer = new FinEntryImporter($this->companyId);
    }

    // ------------------------------------------------------------------ catalog

    /**
     * Platforms with their templates (names and descriptions are translated).
     *
     * @return array<string, array{id: string, name: string, description: string, icon: string, color: string, default_template: string, templates: array<string,array>}>
     */
    public function platforms(): array
    {
        $visual = [
            'microinvest' => ['calculator', '#e8590c'],
            'ajur' => ['calculator', '#1c7ed6'],
            'plus_minus' => ['plus-circle', '#2f9e44'],
            'business_navigator' => ['compass', '#7048e8'],
        ];

        $result = [];

        foreach (self::PLATFORMS as $platform) {
            $templates = $this->templates($platform);

            $result[$platform] = [
                'id' => $platform,
                'name' => __('databackd-gaps-import-templates.platforms.' . $platform . '.name'),
                'description' => __('databackd-gaps-import-templates.platforms.' . $platform . '.desc'),
                'icon' => $visual[$platform][0],
                'color' => $visual[$platform][1],
                'default_template' => array_key_first($templates),
                'templates' => $templates,
            ];
        }

        return $result;
    }

    /**
     * Template definitions: id, platform, layout, name, description, formats, delimiter, encoding, decimal, sample (file name), sample_url.
     * The column synonyms (`roles`) are included too.
     *
     * @return array<string,array>
     */
    public function templates(?string $platform = null): array
    {
        if ($this->templates === null) {
            $this->templates = $this->buildTemplates();
        }

        if ($platform === null) {
            return $this->templates;
        }

        return array_filter($this->templates, fn($template) => $template['platform'] === $platform);
    }

    public function find(?string $id): ?array
    {
        return $id ? ($this->templates()[$id] ?? null) : null;
    }

    public function exists(?string $id): bool
    {
        return $this->find($id) !== null;
    }

    /** @return array<int,string> */
    public function ids(): array
    {
        return array_keys($this->templates());
    }

    /** The template a platform card of "Интеграции" opens in the wizard (the trial balance). */
    public function defaultTemplateFor(string $platform): ?string
    {
        return array_key_first($this->templates($platform)) ?: null;
    }

    public function samplePath(string $id): ?string
    {
        $template = $this->find($id);

        return $template ? public_path(self::SAMPLE_DIR . '/' . $template['sample']) : null;
    }

    public function sampleUrl(string $id): ?string
    {
        $template = $this->find($id);

        return $template ? $this->sampleUrlFor($template['sample']) : null;
    }

    protected function sampleUrlFor(string $file): ?string
    {
        return is_file(public_path(self::SAMPLE_DIR . '/' . $file)) ? asset(self::SAMPLE_DIR . '/' . $file) : null;
    }

    protected function buildTemplates(): array
    {
        $templates = [];

        foreach (self::PLATFORMS as $platform) {
            $style = $this->platformStyle($platform);

            foreach (self::LAYOUTS as $layout) {
                $id = $platform . '_' . $layout;

                $templates[$id] = [
                    'id' => $id,
                    'platform' => $platform,
                    'layout' => $layout,
                    'name' => __('databackd-gaps-import-templates.templates.' . $id . '.name'),
                    'description' => __('databackd-gaps-import-templates.templates.' . $id . '.desc'),
                    'formats' => ['csv', 'xlsx'],
                    'delimiter' => $style['delimiter'],
                    'encoding' => $style['encoding'],
                    'decimal' => $style['decimal'],
                    'sample' => str_replace('_', '-', $id) . '.csv',
                    'sample_url' => $this->sampleUrlFor(str_replace('_', '-', $id) . '.csv'),
                    'roles' => $this->roles($layout, $platform),
                ];
            }
        }

        return $templates;
    }

    /** Typical CSV dialect of the program (only informational: the importer detects delimiter and encoding itself). */
    protected function platformStyle(string $platform): array
    {
        return [
            'microinvest' => ['delimiter' => ';', 'encoding' => 'Windows-1251', 'decimal' => ','],
            // Ажур exports to Excel only; this is what Excel with Bulgarian regional settings writes on "Save as CSV"
            'ajur' => ['delimiter' => ';', 'encoding' => 'Windows-1251', 'decimal' => ','],
            // Plus-Minus sends its accounting reports to Excel (no CSV button there); a CSV is what Excel saves with Bulgarian regional settings
            'plus_minus' => ['delimiter' => ';', 'encoding' => 'Windows-1251', 'decimal' => ','],
            // Excel export goes through OLE (a real .xls); the text modes are "*.csv" (comma) or "*.txt" (tab) and the encoding is not
            // documented - Windows-1251 is assumed for this Windows program (Business Navigator manual, "Експорт на данни към MS Excel...")
            'business_navigator' => ['delimiter' => ',', 'encoding' => 'Windows-1251', 'decimal' => '.'],
        ][$platform];
    }

    // ------------------------------------------------------------------ column synonyms

    /**
     * role => patterns. A string pattern must equal the normalized header (lower case, only letters and digits);
     * an array pattern matches when the normalized header contains ALL its parts. Platform specific variants come first.
     *
     * @return array<string,array<int,string|array<int,string>>>
     */
    protected function roles(string $layout, string $platform): array
    {
        $common = $layout === self::LAYOUT_TRIAL_BALANCE ? $this->trialBalanceRoles() : $this->journalRoles();
        $extra = $this->platformRoles($layout, $platform);

        foreach ($extra as $role => $patterns) {
            // null switches a common role off for the program (e.g. a "Валута" column whose amounts are already in the base currency)
            if ($patterns === null) {
                unset($common[$role]);
                continue;
            }

            $common[$role] = array_merge($patterns, $common[$role] ?? []);
        }

        return $common;
    }

    protected function trialBalanceRoles(): array
    {
        return [
            'account' => ['сметка', 'коднасметка', 'кодсметка', 'сметкакод', 'сметканомер', 'номернасметка', 'счетоводнасметка', 'код', 'account', 'accountcode', 'accountno', 'accountnumber', ['сметк']],
            'date' => ['дата', 'период', 'месец', 'датанаведомост', 'крайнадата', 'date', 'period', 'month', ['дата']],
            'turnover_debit' => [['оборот', 'дебит'], ['оборот', 'дт'], ['turnover', 'debit']],
            'turnover_credit' => [['оборот', 'кредит'], ['оборот', 'кт'], ['turnover', 'credit']],
            // opening balance first, so that a bare "Салдо Дебит" fallback below cannot take its columns
            'open_debit' => [['начал', 'дебит'], ['нач', 'дебит'], ['нач', 'дт'], ['open', 'debit']],
            'open_credit' => [['начал', 'кредит'], ['нач', 'кредит'], ['нач', 'кт'], ['open', 'credit']],
            'close_debit' => [['край', 'дебит'], ['край', 'дт'], ['краен', 'дебит'], ['clos', 'debit'], ['final', 'debit'], ['салдо', 'дебит'], ['салдо', 'дт']],
            'close_credit' => [['край', 'кредит'], ['край', 'кт'], ['краен', 'кредит'], ['clos', 'credit'], ['final', 'credit'], ['салдо', 'кредит'], ['салдо', 'кт']],
            'entity' => ['юридическолице', 'юрлице', 'дружество', 'legalentity', 'entity'],
            'currency' => ['валута', 'currency'],
        ];
    }

    protected function journalRoles(): array
    {
        return [
            'date' => ['дата', 'датанадокумент', 'датанаоперация', 'датанадокумента', 'датанаоперацията', 'date', 'postingdate', 'documentdate', ['дата']],
            'document' => ['документ', 'номернадокумент', 'документномер', 'докномер', 'номер', 'фактура', 'document', 'documentno', 'docno', 'doc', ['докум']],
            'debit_account' => ['дебит', 'дт', 'дебитсметка', 'дебитнасметка', 'дтсметка', 'сметкадебит', 'сметкадт', 'debit', 'debitaccount', ['дебит', 'сметк'], ['дт', 'сметк']],
            'credit_account' => ['кредит', 'кт', 'кредитсметка', 'кредитнасметка', 'ктсметка', 'сметкакредит', 'сметкакт', 'credit', 'creditaccount', ['кредит', 'сметк'], ['кт', 'сметк']],
            'amount' => ['сума', 'стойност', 'суматаевро', 'сумавевро', 'amount', 'sum', 'value', ['сума'], ['стойност'], ['amount']],
            'counterparty' => ['контрагент', 'партньор', 'именаконтрагент', 'доставчик', 'клиент', 'counterparty', 'partner', 'customer', 'supplier', ['контраг'], ['партньор']],
            'description' => ['основание', 'забележка', 'описание', 'обяснение', 'пояснение', 'бележка', 'коментар', 'текст', 'note', 'notes', 'description', 'reason', 'comment', ['основан'], ['забележ'], ['описан']],
            'entity' => ['юридическолице', 'юрлице', 'дружество', 'legalentity', 'entity'],
            'currency' => ['валута', 'currency'],
        ];
    }

    /** Variants that are typical for one program only (guesses, see the class comment); a role set to null is not looked for at all. */
    protected function platformRoles(string $layout, string $platform): array
    {
        $trial = $layout === self::LAYOUT_TRIAL_BALANCE;

        return match ($platform) {
            // Microinvest Делта Pro (the accounting product; Склад Pro is the warehouse program that posts into it) exports its reports
            // "Справки > Оборотна ведомост" and "Справки > Хронологичен регистър" to Excel (Ctrl+F11), PDF or Word. The manual names the
            // fields (сметка/подсметка "453/1", № контиране, дата на осчетоводяване, тип и номер на документ, партньор, сметка Дебит /
            // Кредит, сума, основание, забележка, втора забележка, месец за експорт); the exact column titles of the Excel file are not published.
            'microinvest' => $trial
                ? ['account' => ['сметка', 'шифър', 'шифърнасметка', 'номерсметка', 'сметкаподсметка'],
                    'turnover_debit' => [['обороти', 'дебит'], ['обороти', 'дт']], 'turnover_credit' => [['обороти', 'кредит'], ['обороти', 'кт']]]
                : ['date' => ['датанаосчетоводяване', 'датаосчетоводяване', 'датанаконтиране', 'датаконтиране', 'дата', ['осчетоводяв']],
                    'document' => ['номернадокумент', 'документ', 'докномер'],
                    'debit_account' => ['сметкадебит', 'дебит', 'дебитсметка'], 'credit_account' => ['сметкакредит', 'кредит', 'кредитсметка'],
                    'counterparty' => ['партньор', 'контрагент'], 'description' => ['основание', 'забележка']],
            // Ажур L (Бонев Софт) exports its reports to Excel over OLE; the menus are "Оборотни ведомости > Синтетични/Аналитични" and
            // "Хронологичен опис" and the program abbreviates debit/credit as Дт/Кт. The exact column titles are not published.
            'ajur' => $trial
                ? ['account' => ['сметка', 'синтетичнасметка', 'счетоводнасметка'], 'turnover_debit' => [['оборот', 'дт']], 'turnover_credit' => [['оборот', 'кт']]]
                : ['document' => ['номернадокумент', 'документ'], 'debit_account' => ['сметкадт', 'дтсметка', 'дт'], 'credit_account' => ['сметкакт', 'ктсметка', 'кт'],
                    'description' => ['основание', 'обяснение']],
            // the trial balance has a "Код" column and can be printed in English ("Code"); in the operations grid "Документ" is the document
            // type and "Номер" its number, the text is "Коментар" (plusminus.com blog: year-end closing, typical operations import)
            'plus_minus' => $trial
                ? ['account' => ['код', 'code', 'кодсметка']]
                : ['document' => ['номер', 'номернадокумент', 'докn'], 'counterparty' => ['партньор'], 'description' => ['коментар'],
                    // "Сума" is already in the base currency: mapping "Валута" too would convert it a second time
                    'currency' => null],
            // Business Navigator (Комерс Финанс) user manual: "Оборотна ведомост за материални сметки" = Сметка | Начално салдо, Оборот,
            // Крайно салдо (each Дебит / Кредит) | Име; the synthetic chronological journal has one row per account side
            // (Сметка, Кореспондираща сметка, Дебит, Кредит, Период = first day of the period, Статия, Ред, Папка, Име сметка), so the
            // row with a debit amount is read as "debit Сметка / credit Кореспондираща сметка"; its mirror row has no debit amount
            'business_navigator' => $trial
                ? ['account' => ['сметка', 'номерсметка'], 'turnover_debit' => [['дебит', 'оборот']], 'turnover_credit' => [['кредит', 'оборот']]]
                : ['date' => ['период', 'началонапериода', ['период']], 'document' => ['статия', 'счетоводнастатия', ['статия']],
                    'debit_account' => ['сметка', 'основнасметка'], 'credit_account' => ['кореспондиращасметка', 'корсметка', ['кореспонд']],
                    'amount' => ['дебит', ['национал', 'дебит'], ['дебит']],
                    // the mirror row holds the amount in "Кредит": it is skipped (not rejected as "amount required")
                    'mirror_amount' => ['кредит', ['национал', 'кредит'], ['кредит']]],
            default => [],
        };
    }

    // ------------------------------------------------------------------ matching

    /**
     * Find the columns of a template in the header row.
     *
     * @return array<string,int> role => column index
     */
    public function matchColumns(array $headers, array $roles): array
    {
        $normalized = [];

        foreach ($headers as $index => $header) {
            $normalized[$index] = $this->normalize((string)$header);
        }

        $found = [];
        $used = [];

        // 1. exact names
        foreach ($roles as $role => $patterns) {
            foreach ($patterns as $pattern) {
                if (!is_string($pattern)) {
                    continue;
                }

                foreach ($normalized as $index => $name) {
                    if (!isset($used[$index]) && $name !== '' && $name === $pattern) {
                        $found[$role] = $index;
                        $used[$index] = true;
                        break 2;
                    }
                }
            }
        }

        // 2. headers that contain all parts of a pattern
        foreach ($roles as $role => $patterns) {
            if (isset($found[$role])) {
                continue;
            }

            foreach ($patterns as $pattern) {
                if (!is_array($pattern)) {
                    continue;
                }

                foreach ($normalized as $index => $name) {
                    if (isset($used[$index]) || $name === '') {
                        continue;
                    }

                    if (collect($pattern)->every(fn($part) => str_contains($name, $part))) {
                        $found[$role] = $index;
                        $used[$index] = true;
                        break 2;
                    }
                }
            }
        }

        return $found;
    }

    /**
     * Lower case, only letters and digits (spaces, NBSP, dots, "№" and the like go away). A Cyrillic header typed with a Latin lookalike
     * ("Cметка" with a Latin C, "Kт") gets the Cyrillic letter, so the Cyrillic patterns still match.
     */
    protected function normalize(string $header): string
    {
        if (preg_match('/\p{Cyrillic}/u', $header)) {
            $header = strtr($header, [
                'A' => 'А', 'B' => 'В', 'C' => 'С', 'E' => 'Е', 'H' => 'Н', 'K' => 'К', 'M' => 'М', 'O' => 'О', 'P' => 'Р', 'T' => 'Т', 'X' => 'Х', 'Y' => 'У',
                'a' => 'а', 'c' => 'с', 'e' => 'е', 'k' => 'к', 'o' => 'о', 'p' => 'р', 'x' => 'х', 'y' => 'у',
            ]);
        }

        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($header)));
    }

    /**
     * Find the real header row. Reports of the accounting programs start with title rows (company, report name, period) and often have a
     * two-row header: a group row ("Начално салдо", "Обороти", "Крайно салдо" over two merged cells) and a sub-row ("Дебит | Кредит").
     * The current header and each of the first HEADER_SCAN_ROWS rows are tried alone and merged with the next row (the group text is
     * carried to the right over the empty merged cells); the candidate that finds the most essential (then all) roles wins, the earliest
     * on a tie, so a file whose first row already is the header is never changed.
     *
     * @return array{0: array, 1: array, 2: int} headers, data rows, number of rows dropped above the data (title rows + extra header row)
     */
    protected function locateHeader(array $headers, array $rows, array $roles, array $essential): array
    {
        // a merged pair must find more than a single row, otherwise a title row would be glued onto a one-row header
        $score = function (array $candidate, int $used = 1) use ($roles, $essential) {
            $found = $this->matchColumns($candidate, $roles);

            return (count(array_intersect($essential, array_keys($found))) * 100 + count($found)) * 2 - ($used - 1);
        };

        $best = $score($headers);

        if ($best >= count($essential) * 200) {
            return [$headers, $rows, 0];
        }

        // FinEntryImporter names empty header cells "Column N": they are empty cells of a merged group here
        $first = array_map(fn($cell, $i) => $cell === 'Column ' . ($i + 1) ? null : $cell, $headers, array_keys($headers));
        $lines = array_merge([$first], $rows);
        $width = max(array_map('count', $lines));
        $choice = null;

        foreach (array_slice($lines, 0, self::HEADER_SCAN_ROWS + 1) as $index => $line) {
            $line = array_pad(array_values($line), $width, null);
            $candidates = [[$line, 1]];
            $next = isset($lines[$index + 1]) ? array_pad(array_values($lines[$index + 1]), $width, null) : null;

            // a sub-header row has text only (no amounts)
            if ($next !== null && !$this->isEmptyLine($next) && !collect($next)->contains(fn($cell) => $cell !== null && $this->isNumberLike($cell))) {
                $candidates[] = [$this->mergeHeaderRows($line, $next), 2];
            }

            foreach ($candidates as [$candidate, $used]) {
                if ($index === 0 && $used === 1) {
                    continue; // the current header, scored above
                }

                $value = $score($candidate, $used);

                if ($value > $best) {
                    [$best, $choice] = [$value, [$candidate, $index, $used]];
                }
            }
        }

        if ($choice === null) {
            return [$headers, $rows, 0];
        }

        [$candidate, $index, $used] = $choice;

        foreach ($candidate as $i => $name) {
            $name = trim((string)$name);
            $candidate[$i] = $name === '' ? 'Column ' . ($i + 1) : $name;
        }

        $data = [];

        foreach (array_slice($lines, $index + $used) as $line) {
            if (!$this->isEmptyLine($line)) {
                $data[] = array_slice(array_pad(array_values($line), $width, null), 0, $width);
            }
        }

        // rows of the original table above the data: title rows and the extra header row (the original header is not a data row)
        return [$candidate, $data, $index + $used - 1];
    }

    /** "Начално салдо", null, "Обороти", null over "Дебит", "Кредит", "Дебит", "Кредит" => "Начално салдо Дебит", "Начално салдо Кредит", ... */
    protected function mergeHeaderRows(array $top, array $bottom): array
    {
        $merged = [];
        $group = '';

        foreach ($top as $i => $cell) {
            $cell = trim((string)$cell);
            $sub = trim((string)($bottom[$i] ?? ''));

            if ($cell !== '') {
                $group = $cell;
            }
            elseif ($sub === '') {
                $group = '';
            }

            $merged[$i] = trim(($cell !== '' ? $cell : ($sub !== '' ? $group : '')) . ' ' . $sub);
        }

        return $merged;
    }

    protected function isEmptyLine(array $line): bool
    {
        foreach ($line as $cell) {
            if ($cell !== null && trim((string)$cell) !== '') {
                return false;
            }
        }

        return true;
    }

    protected function isNumberLike(mixed $cell): bool
    {
        return is_int($cell) || is_float($cell) || (trim((string)$cell) !== '' && $this->importer->parseAmount($cell) !== null);
    }

    // ------------------------------------------------------------------ apply

    /**
     * Apply a template to a table read by FinEntryImporter::readFile()/readCsvString().
     *
     * $options: `date` (Y-m-d) – date of the trial balance when the file has no date column; `after_closing` (bool) – the trial balance was
     * taken after the year-end closing, so P&L accounts are read from their natural-side turnover (see trialBalanceAmount()).
     *
     * Returns:
     *  - lines: physical row numbers of `rows` in the file (empty when the table had none) - AFV (S12)
     *  - headers, rows: the table to use from now on (original columns + computed ones at the end; title rows above the real header and
     *    skipped rows removed)
     *  - mapping: [fieldId => columnIndex] pre-filled from the template (columns that were not found are missing = manual)
     *  - found: [role => original header], missing: role[] (essential roles that were not found), virtual_columns: int[] (indexes of the computed columns)
     *  - skipped: [header_rows, totals, zero, mirror, analytic, balance_only, closing] counts of dropped rows, rows_in/rows_out
     *  - error: null or `unknown_template` / `date_required` (headers and rows are then without computed columns)
     *
     * @param array{headers: array, rows: array} $table
     */
    public function apply(string $templateId, array $table, array $options = []): array
    {
        $headers = array_values($table['headers'] ?? []);
        $rows = array_values($table['rows'] ?? []);
        $template = $this->find($templateId);
        // AFV (S12): physical row numbers of the rows (FinEntryImporter::readFile()['lines']), carried to the output rows
        $lines = array_values($table['lines'] ?? []);
        $lines = count($lines) === count($rows) ? $lines : [];

        $result = [
            'template' => $templateId,
            'layout' => $template['layout'] ?? null,
            'headers' => $headers,
            'rows' => $rows,
            'lines' => $lines,
            'mapping' => [],
            'found' => [],
            'missing' => [],
            'virtual_columns' => [],
            'skipped' => ['header_rows' => 0, 'totals' => 0, 'zero' => 0, 'mirror' => 0, 'analytic' => 0, 'balance_only' => 0, 'closing' => 0],
            'rows_in' => count($rows),
            'rows_out' => count($rows),
            'error' => null,
        ];

        if (!$template) {
            $result['error'] = 'unknown_template';

            return $result;
        }

        $layout = $template['layout'];
        [$headers, $rows, $result['skipped']['header_rows']] = $this->locateHeader($headers, $rows, $template['roles'], self::ESSENTIAL[$layout]);
        [$result['headers'], $result['rows'], $result['rows_out']] = [$headers, $rows, count($rows)];
        $result['lines'] = $lines ? array_slice($lines, $result['skipped']['header_rows']) : []; // the rows above the real header are dropped
        $columns = $this->matchColumns($headers, $template['roles']);

        foreach ($columns as $role => $index) {
            $result['found'][$role] = (string)$headers[$index];
        }

        $result['missing'] = array_values(array_diff(self::ESSENTIAL[$layout], array_keys($columns)));

        if ($result['missing']) {
            // the computed columns are not possible: map what exists column by column, the rest is manual
            foreach (self::DIRECT_FIELDS[$layout] as $role => $field) {
                if (isset($columns[$role])) {
                    $result['mapping'][$field] = $columns[$role];
                }
            }

            return $result;
        }

        // "1.234,50" and "0.125" are read with the separator the column uses ("1,234" alone is ambiguous: the program's one decides)
        $this->decimals = $this->importer->detectDecimalSeparators($rows);
        $this->decimal = $template['decimal'] ?? null;

        return $layout === self::LAYOUT_TRIAL_BALANCE
            ? $this->applyTrialBalance($result, $columns, $options['date'] ?? null, !empty($options['after_closing']))
            : $this->applyJournal($result, $columns);
    }

    protected function applyTrialBalance(array $result, array $columns, ?string $periodDate, bool $afterClosing = false): array
    {
        $hasDate = isset($columns['date']);

        if (!$hasDate && !$periodDate) {
            $result['error'] = 'date_required';

            return $result;
        }

        $this->loadChart();

        $width = count($result['headers']);
        $out = [];
        $entries = [];
        $groups = [];

        // codes in the file: an analytic row ("411/1") whose parent ("411") is in the file too is already in the parent's numbers
        $codes = [];

        foreach ($result['rows'] as $row) {
            $codes[$this->plainCode(array_values($row)[$columns['account']] ?? null)] = true;
        }

        $lines = $result['lines'] ?? [];
        $outLines = [];

        foreach ($result['rows'] as $i => $row) {
            $row = array_pad(array_values($row), $width, null);
            $raw = $this->plainCode($row[$columns['account']] ?? null);

            // total rows: no code, "Общо: ..." or a group subtotal ("6", "60") that is not an account of the chart
            if ($raw === '' || preg_match(self::TOTAL_PATTERN, $raw) || (preg_match('/^\d{1,2}$/', $raw) && !isset($this->chart[$raw]))) {
                $result['skipped']['totals']++;
                continue;
            }

            if ($this->hasParentIn($raw, $codes)) {
                $result['skipped']['analytic']++;
                continue;
            }

            $code = $this->chartCode($raw);
            $numbers = $this->readNumbers($row, $columns, ['turnover_debit', 'turnover_credit', 'close_debit', 'close_credit']);

            // analytics without their parent row ("4531", "4532") are added up into one row of the synthetic account: a VAT account
            // with a debit and a credit analytic gives its net balance instead of a negative row that the importer rejects
            if ($raw !== $code && $numbers[4] === null) {
                $key = $code . '|' . ($hasDate ? (string)$row[$columns['date']] : '');

                if (isset($groups[$key])) {
                    foreach ([0, 1, 2, 3] as $i) {
                        $entries[$groups[$key]][2][$i] += $numbers[$i];
                    }

                    $result['skipped']['analytic']++;
                    continue;
                }

                $groups[$key] = count($entries);
            }

            $entries[] = [$row, $code, $numbers, $lines[$i] ?? null];
        }

        foreach ($entries as [$row, $code, [$td, $tc, $cd, $cc, $bad, $currency], $line]) {
            $net = $this->trialBalanceAmount($code, $td, $tc, $cd, $cc, $afterClosing);

            if ($bad === null && abs($net) < 0.005) {
                $result['skipped']['zero']++;
                continue;
            }

            $row[] = $code;
            $row[] = $bad ?? $this->formatAmount($net, isset($columns['currency']) ? null : $currency);

            if (!$hasDate) {
                $row[] = $periodDate;
            }

            $out[] = $row;
            $outLines[] = $line;
        }

        // the account is computed too: an analytic code of the file becomes the synthetic account of the chart
        $result['headers'][] = __('databackd-gaps-import-templates.virtual.account');
        $result['headers'][] = __('databackd-gaps-import-templates.virtual.amount');
        $amountIndex = $width + 1;
        $result['virtual_columns'] = [$width, $amountIndex];
        $dateIndex = $columns['date'] ?? null;

        if (!$hasDate) {
            $result['headers'][] = __('databackd-gaps-import-templates.virtual.date');
            $dateIndex = $width + 2;
            $result['virtual_columns'][] = $dateIndex;
        }

        $result['rows'] = $out;
        $result['lines'] = in_array(null, $outLines, true) ? [] : $outLines;
        $result['rows_out'] = count($out);
        $result['mapping'] = ['account_code' => $width, 'amount' => $amountIndex, 'entry_date' => $dateIndex];

        foreach (['entity' => 'entity', 'currency' => 'currency'] as $role => $field) {
            if (isset($columns[$role])) {
                $result['mapping'][$field] = $columns[$role];
            }
        }

        return $result;
    }

    protected function applyJournal(array $result, array $columns): array
    {
        $this->loadChart();

        $width = count($result['headers']);
        $out = [];
        $lines = $result['lines'] ?? [];
        $outLines = [];

        foreach ($result['rows'] as $i => $row) {
            $row = array_pad(array_values($row), $width, null);

            $debit = $this->chartCode($row[$columns['debit_account']] ?? null);
            $credit = $this->chartCode($row[$columns['credit_account']] ?? null);

            if ($debit === '' && $credit === '') {
                $result['skipped']['totals']++;
                continue;
            }

            $debitType = $this->typeOf($debit);
            $creditType = $this->typeOf($credit);
            $debitPl = in_array($debitType, ['expense', 'revenue'], true);
            $creditPl = in_array($creditType, ['expense', 'revenue'], true);

            // closing entries are not flows: P&L to P&L (Дт 611 / Кт 601) and P&L against a result account (Дт 702 / Кт 123, Дт 123 / Кт 611)
            if (($debitPl && $creditPl) || ($debitPl && str_starts_with($credit, self::RESULT_ACCOUNT_PREFIX))
                || ($creditPl && str_starts_with($debit, self::RESULT_ACCOUNT_PREFIX))) {
                $result['skipped']['closing']++;
                continue;
            }

            // the P&L side of the posting; a reversal (debit of revenue / credit of expense) gets a negative amount
            if ($debitType === 'expense') {
                [$account, $sign] = [$debit, 1];
            }
            elseif ($creditType === 'revenue') {
                [$account, $sign] = [$credit, 1];
            }
            elseif ($debitType === 'revenue') {
                [$account, $sign] = [$debit, -1];
            }
            elseif ($creditType === 'expense') {
                [$account, $sign] = [$credit, -1];
            }
            else {
                $result['skipped']['balance_only']++;
                continue;
            }

            $raw = $row[$columns['amount']] ?? null;

            if ($raw === null || trim((string)$raw) === '') {
                // the mirror row of a program that prints every posting twice (its amount is in the other column) is the same posting;
                // any other row without an amount goes on and the importer rejects it ("amount required")
                if (isset($columns['mirror_amount']) && trim((string)($row[$columns['mirror_amount']] ?? '')) !== '') {
                    $result['skipped']['mirror']++;
                    continue;
                }

                $row[] = $account;
                $row[] = '';
                $out[] = $row;
                $outLines[] = $lines[$i] ?? null;
                continue;
            }

            $amount = $this->amount($raw, $columns['amount']);

            if ($amount !== null && abs($amount) < 0.005) {
                $result['skipped']['zero']++;
                continue;
            }

            $row[] = $account;
            $row[] = $amount === null ? (string)$raw : $this->formatAmount($sign * $amount, isset($columns['currency']) ? null : $this->currencyMarker($raw));
            $out[] = $row;
            $outLines[] = $lines[$i] ?? null;
        }

        $result['headers'][] = __('databackd-gaps-import-templates.virtual.account');
        $result['headers'][] = __('databackd-gaps-import-templates.virtual.amount');
        $result['virtual_columns'] = [$width, $width + 1];
        $result['rows'] = $out;
        $result['lines'] = in_array(null, $outLines, true) ? [] : $outLines;
        $result['rows_out'] = count($out);
        $result['mapping'] = ['entry_date' => $columns['date'], 'account_code' => $width, 'amount' => $width + 1];

        foreach (['document' => 'document_no', 'counterparty' => 'counterparty_name', 'description' => 'description', 'entity' => 'entity', 'currency' => 'currency'] as $role => $field) {
            if (isset($columns[$role])) {
                $result['mapping'][$field] = $columns[$role];
            }
        }

        return $result;
    }

    /**
     * Amount that a trial balance row contributes: P&L accounts = turnover on their natural side, balance sheet accounts = closing balance
     * on their natural side (contra accounts like 241 have a credit balance). P&L turnover is net (debit - credit for expenses), so credit
     * notes and reversals of the period are deducted. After the year-end closing (Дт 123 / Кт 602) the net is 0: only when the user says the
     * trial balance is after the closing ($afterClosing) the natural-side turnover is taken (reversals are then not deducted). Accounts that
     * are not in the chart of the company get a best-effort amount so that the importer reports "unknown account" instead of the row
     * silently disappearing.
     */
    protected function trialBalanceAmount(string $code, float $td, float $tc, float $cd, float $cc, bool $afterClosing = false): float
    {
        $type = $this->typeOf($code);

        if ($type === 'expense') {
            return $afterClosing ? $td : $td - $tc;
        }

        if ($type === 'revenue') {
            return $afterClosing ? $tc : $tc - $td;
        }

        if ($type === 'asset') {
            // review A: analytic accounts of a contra account (2411 under 241) also have a credit balance
            return FinAccount::isContraCode($code) ? $cc - $cd : $cd - $cc;
        }

        if ($type === 'liability' || $type === 'equity') {
            return $cc - $cd;
        }

        return abs($cd - $cc);
    }

    /**
     * Read the numeric cells of a row.
     *
     * @return array{0: float, 1: float, 2: float, 3: float, 4: string|null, 5: string|null} the four numbers, the raw text of the first cell
     *         that is not a number and the first currency written in a cell ("1 234,50 лв.")
     */
    protected function readNumbers(array $row, array $columns, array $roles): array
    {
        $numbers = [];
        $bad = null;
        $currency = null;

        foreach ($roles as $role) {
            $raw = $row[$columns[$role]] ?? null;

            if ($raw === null || trim((string)$raw) === '') {
                $numbers[] = 0.0;
                continue;
            }

            $value = $this->amount($raw, $columns[$role]);
            $currency ??= $this->currencyMarker($raw);

            if ($value === null) {
                $bad ??= (string)$raw;
                $value = 0.0;
            }

            $numbers[] = $value;
        }

        return [$numbers[0], $numbers[1], $numbers[2], $numbers[3], $bad, $currency];
    }

    protected function amount(mixed $value, int $column): ?float
    {
        return $this->importer->parseAmount($value, $this->decimals[$column] ?? $this->decimal);
    }

    /** Currency text of an amount cell ("лв." of "100 000,00 лв."), kept on the computed amount so that the importer still converts it. */
    protected function currencyMarker(mixed $value): ?string
    {
        return is_string($value) && preg_match(FinEntryImporter::AMOUNT_CURRENCY_PATTERN, $value, $m) ? $m[1] : null;
    }

    protected function formatAmount(float $amount, ?string $currency = null): string
    {
        return number_format(round($amount, 2), 2, '.', '') . ($currency !== null ? ' ' . $currency : '');
    }

    protected function loadChart(): void
    {
        if ($this->chart !== null) {
            return;
        }

        $this->chart = [];

        FinAccount::withoutGlobalScopes()->where('company_id', $this->companyId)->get(['code', 'type'])
            ->each(fn($account) => $this->chart[(string)$account->code] = $account->type);
    }

    /** Account type from the chart; accounts that are not in it fall back to the class digit of the Bulgarian chart (6 = expenses, 7 = revenues). */
    protected function typeOf(string $code): ?string
    {
        if ($code === '') {
            return null;
        }

        if (isset($this->chart[$code])) {
            return $this->chart[$code];
        }

        return match (substr($code, 0, 1)) {
            '6' => 'expense',
            '7' => 'revenue',
            default => null,
        };
    }

    /** Accounts may be analytic ("411/1", "411.1", "411-01", "4111"): they are posted to their synthetic account when only that one is in the chart. */
    protected function chartCode(mixed $value): string
    {
        $code = $this->plainCode($value);

        if ($code === '' || isset($this->chart[$code])) {
            return $code;
        }

        $first = preg_split('/\D/', $code)[0] ?? '';

        if ($first !== '' && isset($this->chart[$first])) {
            return $first;
        }

        return (strlen($first) > 3 && isset($this->chart[substr($first, 0, 3)])) ? substr($first, 0, 3) : $code;
    }

    /** True when a parent of an analytic code ("411" or "411/1" of "411/1/2", "411" of "4111") is one of $codes (the codes in the file). */
    protected function hasParentIn(string $code, array $codes): bool
    {
        $parents = [];

        if (preg_match_all('/[^\p{L}\p{N}]/u', $code, $matches, PREG_OFFSET_CAPTURE)) {
            foreach ($matches[0] as [, $offset]) {
                $parents[] = rtrim(substr($code, 0, $offset));
            }
        }
        elseif (preg_match('/^\d{4,}$/', $code)) {
            // also when "6021" is an account of the chart: with "602" in the file its numbers are in the parent's
            $parents[] = substr($code, 0, 3);
        }

        foreach ($parents as $parent) {
            if ($parent !== '' && $parent !== $code && isset($codes[$parent])) {
                return true;
            }
        }

        return false;
    }

    /** Excel stores codes as numbers: 501.0 -> "501". */
    protected function plainCode(mixed $value): string
    {
        if ($value === null) {
            return '';
        }

        if (is_float($value) && floor($value) == $value) {
            return (string)(int)$value;
        }

        return trim((string)$value);
    }

    // ------------------------------------------------------------------ presentation

    /**
     * Display data for the mapping step of the wizard (everything translated).
     *
     * @param array $result a result of apply() (without or with headers/rows)
     * @return array{name: string, description: string, layout_note: string, sample_url: ?string, found: array, missing: array, skipped: array, rows_in: int, rows_out: int, computed: bool}
     */
    public function describe(array $result): array
    {
        $template = $this->find($result['template'] ?? null);

        $roleLabel = fn($role) => __('databackd-gaps-import-templates.roles.' . $role);
        $found = [];

        foreach ($result['found'] ?? [] as $role => $header) {
            $found[] = ['role' => $roleLabel($role), 'column' => $header];
        }

        $skipped = [];

        foreach ($result['skipped'] ?? [] as $key => $count) {
            if ($count > 0) {
                $skipped[] = ['label' => __('databackd-gaps-import-templates.skipped.' . $key), 'count' => $count];
            }
        }

        return [
            'name' => $template['name'] ?? '',
            'description' => $template['description'] ?? '',
            'layout_note' => $template ? __('databackd-gaps-import-templates.layouts.' . $template['layout'] . '.how') : '',
            'sample_url' => $template['sample_url'] ?? null,
            'found' => $found,
            'missing' => array_map($roleLabel, $result['missing'] ?? []),
            'skipped' => $skipped,
            'rows_in' => (int)($result['rows_in'] ?? 0),
            'rows_out' => (int)($result['rows_out'] ?? 0),
            'computed' => !empty($result['virtual_columns']),
        ];
    }

}

<?php

namespace App\Services\Databackd\Data;

use App\Models\Company;
use App\Models\Currency;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinDimension;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Models\Databackd\FinImportBatch;
use App\Services\Databackd\Gaps\FxRates\FxRateService; // AFV (S8)
use Carbon\Carbon;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use PhpOffice\PhpSpreadsheet\IOFactory;
use PhpOffice\PhpSpreadsheet\RichText\RichText;
use PhpOffice\PhpSpreadsheet\Shared\Date as ExcelDate;
use PhpOffice\PhpSpreadsheet\Worksheet\Worksheet;

/**
 * Core of every data import into fin_entries (Excel/CSV upload, Google Sheets, CSV URL, REST API).
 *
 * Mapping format (canonical): [fieldId => columnIndex] for the mapped fields only, e.g. ['entry_date' => 0, 'amount' => 3].
 * Forms post it the other way round ([columnIndex => fieldId]); convert with mappingFromColumns() / columnsFromMapping().
 *
 * Tenant: all lookups and writes use the explicit company id given to the constructor (works in queue jobs without a logged-in user).
 */
class FinEntryImporter
{

    const STATUS_PENDING = 'pending';
    const STATUS_PROCESSING = 'processing';
    const STATUS_COMPLETED = 'completed';
    const STATUS_PARTIAL = 'partial'; // finished, but some rows failed
    const STATUS_FAILED = 'failed';   // finished, nothing imported
    const STATUS_UNDONE = 'undone';

    const STATUSES = [self::STATUS_PENDING, self::STATUS_PROCESSING, self::STATUS_COMPLETED, self::STATUS_PARTIAL, self::STATUS_FAILED, self::STATUS_UNDONE];

    /** Imports save at most this many error rows in fin_import_batches.errors. */
    const MAX_STORED_ERRORS = 500;

    /** AFV (S12): cells of a failed row kept with its error for the "rows with errors" download. */
    const MAX_ERROR_VALUE_COLUMNS = 60;

    /** Fixed BGN/EUR peg (1 EUR = 1.95583 BGN). */
    const BGN_PER_EUR = 1.95583;

    /** Largest accepted amount (1 trillion) and amount in the base currency; fin_entries.amount / amount_base are decimal(15,2). */
    const MAX_AMOUNT = 1.0E+12;
    const MAX_AMOUNT_BASE = 9999999999999.99;

    /** Currency text allowed inside an amount cell ("99,50 лв.", "€ 10"); see parseAmount() / amountCurrency(). */
    const AMOUNT_CURRENCY_PATTERN = '/(лева|лв\.?|евро|€|eur|bgn|usd|gbp|\$|£)/iu';

    /** DoS guard (S6/P4) on uploaded xlsx/xls: rejected before the full sheet is loaded into memory, see readFile(). */
    const MAX_IMPORT_ROWS = 50000;
    const MAX_IMPORT_COLUMNS = 300;
    const MAX_IMPORT_SHEETS = 20;

    protected int $companyId;
    protected ?Company $company = null;
    protected ?string $baseCurrency = null;

    protected ?array $accountsByCode = null;
    protected ?array $accountsByName = null;
    protected ?array $entitiesByName = null;
    protected ?array $dimensions = null;
    protected array $currencyRates = [];
    protected ?FxRateService $fxRates = null; // AFV (S8): dated rates of fin_fx_rates

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?: company()->id;
    }

    // ------------------------------------------------------------------ reading

    /**
     * Read an uploaded/local xlsx, xls or csv file.
     *
     * @return array{headers: array<int,string>, rows: array<int,array<int,mixed>>}
     */
    public function readFile(UploadedFile|string $path): array
    {
        if ($path instanceof UploadedFile) {
            $extension = strtolower($path->getClientOriginalExtension());
            $file = $path->getRealPath();
        } else {
            $extension = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $file = $path;
        }

        if (!is_file($file)) {
            throw new \InvalidArgumentException('File not found: ' . $file);
        }

        $head = (string)file_get_contents($file, false, null, 0, 8);
        $type = null;

        if (str_starts_with($head, 'PK')) {
            $type = 'Xlsx';
        }
        elseif (str_starts_with($head, "\xD0\xCF\x11\xE0")) {
            $type = 'Xls';
        }
        elseif (in_array($extension, ['xlsx', 'xls'], true)) {
            // extension says Excel but the content is text: treat it as CSV
            $type = null;
        }

        if ($type === null) {
            return $this->readCsvString((string)file_get_contents($file));
        }

        // SECURITY (S6/P4) - DoS guard: only the Xlsx/Xls reader for the sniffed type is used (never PhpSpreadsheet's
        // auto-detection, which would also accept Gnumeric/ODS/Xml/Html), in read-only mode, and listWorksheetInfo()
        // is a cheap structural scan (it does not load cell data) used to reject an oversized/zip-bomb-style sheet
        // before the full, memory-heavy load below runs.
        $reader = IOFactory::createReader($type);
        $reader->setReadDataOnly(true);
        $sheets = $reader->listWorksheetInfo($file);

        if (!$sheets) {
            return $this->buildTable([]);
        }

        if (count($sheets) > self::MAX_IMPORT_SHEETS) {
            throw new \RuntimeException(__('databackd-misc.import.too_many_sheets'));
        }

        foreach ($sheets as $sheet) {
            if (($sheet['totalRows'] ?? 0) > self::MAX_IMPORT_ROWS || ($sheet['totalColumns'] ?? 0) > self::MAX_IMPORT_COLUMNS) {
                throw new \RuntimeException(__('databackd-misc.import.too_large_cells'));
            }
        }

        // Only the first worksheet is imported (the upload help says so). It used to go through Maatwebsite's ToArray, which
        // runs the import object once per sheet - a workbook with a second "notes" sheet imported the LAST sheet - and
        // returns formulas as text ("=C2*2") instead of their values. Number formats are loaded (read-data-only off) so
        // date cells can be told from plain numbers; see sheetToArray().
        $reader->setReadDataOnly(false);
        $reader->setLoadSheetsOnly([$sheets[0]['worksheetName']]);

        try {
            $spreadsheet = $reader->load($file);
            $data = $this->sheetToArray($spreadsheet->getSheet(0));
            $spreadsheet->disconnectWorksheets();
            unset($spreadsheet);
        } finally {
            // the readers switch PhpSpreadsheet's static calendar for a 1904 (Mac) workbook - do not leak it to the next file
            ExcelDate::setExcelCalendar(ExcelDate::CALENDAR_WINDOWS_1900);
        }

        return $this->buildTable($data);
    }

    /**
     * Cell values of a worksheet as a 2D array, up to the last cell that holds data (formatted empty rows/columns are ignored).
     * Formulas give the value Excel saved with the file (cached result); without one PhpSpreadsheet calculates it, and a
     * formula it cannot calculate (e.g. a reference to a sheet that was not loaded) becomes an empty cell. Date-formatted
     * numbers become "Y-m-d" strings in the calendar of the workbook (1900 or 1904), rich text becomes plain text.
     */
    protected function sheetToArray(Worksheet $sheet): array
    {
        $lastRow = $sheet->getHighestDataRow();
        $lastColumn = \PhpOffice\PhpSpreadsheet\Cell\Coordinate::columnIndexFromString($sheet->getHighestDataColumn());
        $data = [];

        for ($row = 1; $row <= $lastRow; $row++) {
            $record = [];

            for ($column = 1; $column <= $lastColumn; $column++) {
                // getCell() would create every empty cell of the range in memory
                if (!$sheet->cellExists([$column, $row])) {
                    $record[] = null;
                    continue;
                }

                $cell = $sheet->getCell([$column, $row]);
                $value = $cell->getValue();

                if ($cell->isFormula()) {
                    $value = $cell->getOldCalculatedValue();

                    // no saved result (files written by some libraries): calculate it - but not when it refers to another
                    // sheet, which is not loaded and would be silently resolved against this one (=SUM(Notes!B1:B2) -> B1:B2)
                    if ($value === null && !str_contains((string)$cell->getValue(), '!')) {
                        try {
                            $value = $cell->getCalculatedValue();
                        } catch (\Throwable $e) {
                            $value = null;
                        }
                    }

                    // an error result (#REF!, #DIV/0!, ...) or a formula that could not be evaluated is not data
                    if (is_string($value) && (str_starts_with($value, '#') || str_starts_with($value, '='))) {
                        $value = null;
                    }
                }

                if ($value instanceof RichText) {
                    $value = $value->getPlainText();
                }
                elseif ((is_int($value) || is_float($value)) && ExcelDate::isDateTime($cell, $value)) {
                    try {
                        $value = ExcelDate::excelToDateTimeObject($value)->format('Y-m-d');
                    } catch (\Throwable $e) {
                        // out-of-range serial: keep the number, parseDate() reports it
                    }
                }

                $record[] = $value;
            }

            $data[] = $record;
        }

        return $data;
    }

    /**
     * Parse CSV text: UTF-8 (with or without BOM), UTF-16 LE/BE (Excel "Unicode Text") or Windows-1251; delimiter from an
     * Excel "sep=X" first line, otherwise , ; or tab - whichever occurs most often outside quotes in the first non-empty line.
     *
     * @return array{headers: array<int,string>, rows: array<int,array<int,mixed>>}
     */
    public function readCsvString(string $csv): array
    {
        $csv = $this->csvToUtf8($csv);
        $csv = str_replace(["\r\n", "\r"], "\n", $csv);
        $delimiter = null;
        $firstLine = 1; // AFV (S12): physical row number of the first CSV record (error messages quote the row the user sees)

        // Excel's "sep=;" hint line (written by some exporters so that Excel splits the columns): use it and drop it
        if (preg_match('/^sep=(.)\n/u', $csv, $m)) {
            $delimiter = $m[1];
            $csv = substr($csv, strlen($m[0]));
            $firstLine = 2;
        }

        $headerLine = '';

        foreach (explode("\n", $csv) as $line) {
            if (trim($line) !== '') {
                $headerLine = $line;
                break;
            }
        }

        if ($delimiter === null) {
            // quoted text is skipped: a header such as "Сума, EUR" must not make "," the delimiter of a ";" file
            $unquoted = preg_replace('/"[^"]*"/u', '', $headerLine);
            $delimiter = ',';
            $best = -1;

            foreach ([',', ';', "\t"] as $candidate) {
                $count = substr_count($unquoted, $candidate);

                if ($count > $best) {
                    $best = $count;
                    $delimiter = $candidate;
                }
            }
        }

        $stream = fopen('php://temp', 'r+');
        fwrite($stream, $csv);
        rewind($stream);

        $data = [];

        while (($record = fgetcsv($stream, 0, $delimiter, '"', '')) !== false) {
            // SECURITY (S6/P4) - DoS guard: stop reading (and never build the full in-memory table) once the row
            // cap is exceeded, same limit as the Excel path in readFile().
            if (count($data) > self::MAX_IMPORT_ROWS) {
                fclose($stream);

                throw new \RuntimeException(__('databackd-misc.import.too_large_rows'));
            }

            $data[] = $record;
        }

        fclose($stream);

        // a CSV record is one row in Excel even when a quoted cell spans several text lines
        return $this->buildTable($data, $firstLine);
    }

    /**
     * CSV bytes to UTF-8 without a BOM. UTF-16 is recognized by its BOM (Excel "Unicode Text" = UTF-16 LE with BOM) or,
     * without one, by the NUL bytes of mostly-ASCII text; text that is not valid UTF-8 is taken as Windows-1251.
     */
    protected function csvToUtf8(string $csv): string
    {
        if (str_starts_with($csv, "\xEF\xBB\xBF")) {
            return substr($csv, 3);
        }

        $utf16 = null;

        if (str_starts_with($csv, "\xFF\xFE")) {
            $utf16 = 'UTF-16LE';
            $csv = substr($csv, 2);
        }
        elseif (str_starts_with($csv, "\xFE\xFF")) {
            $utf16 = 'UTF-16BE';
            $csv = substr($csv, 2);
        }
        elseif (strlen($csv) >= 4 && str_contains($head = substr($csv, 0, 1000), "\0")) {
            // no BOM: digits, separators and line breaks are ASCII, so every other byte is NUL (odd bytes for LE)
            $even = $odd = 0;

            for ($i = 0, $n = strlen($head) - 1; $i < $n; $i += 2) {
                $even += $head[$i] === "\0" ? 1 : 0;
                $odd += $head[$i + 1] === "\0" ? 1 : 0;
            }

            if (max($even, $odd) * 4 >= strlen($head) / 2) {
                $utf16 = $odd >= $even ? 'UTF-16LE' : 'UTF-16BE';
            }
        }

        if ($utf16 !== null) {
            return mb_convert_encoding($csv, 'UTF-8', $utf16);
        }

        return mb_check_encoding($csv, 'UTF-8') ? $csv : mb_convert_encoding($csv, 'UTF-8', 'Windows-1251');
    }

    /**
     * Turn a raw 2D array into headers + rows: first non-empty row = headers, empty rows are dropped, rows are padded to the header width.
     * `lines` holds the physical row number of every data row (1 = first row of the sheet / file), so that the errors name the row the user
     * sees in Excel even when the file has empty rows or title rows (AFV S12).
     *
     * @return array{headers: array<int,string>, rows: array<int,array<int,mixed>>, lines: array<int,int>}
     */
    protected function buildTable(array $data, int $firstLine = 1): array
    {
        $headers = null;
        $rows = [];
        $lines = [];

        foreach (array_values($data) as $index => $record) {
            $record = array_map(fn($v) => is_string($v) ? trim($v) : $v, array_values($record));

            if ($this->isEmptyRow($record)) {
                continue;
            }

            if ($headers === null) {
                $headers = $record;
                continue;
            }

            $rows[] = $record;
            $lines[] = $firstLine + $index;
        }

        $headers = $headers ?? [];

        // trailing empty header cells are not columns
        while ($headers && ($headers[count($headers) - 1] === null || $headers[count($headers) - 1] === '')) {
            array_pop($headers);
        }

        // the table is as wide as its widest record (trailing empty cells ignored), not the header: a title row
        // ("Демо Финанс Груп ЕООД;;;;") or a short group header must not cut the data rows
        $width = count($headers);

        foreach ($rows as $row) {
            $w = count($row);

            while ($w > 0 && ($row[$w - 1] === null || $row[$w - 1] === '')) {
                $w--;
            }

            $width = max($width, $w);
        }

        $headers = array_pad($headers, $width, null);

        foreach ($headers as $i => $header) {
            $headers[$i] = ($header === null || $header === '') ? 'Column ' . ($i + 1) : (string)$header;
        }

        foreach ($rows as $i => $row) {
            $rows[$i] = array_slice(array_pad($row, $width, null), 0, $width);
        }

        return ['headers' => $headers, 'rows' => $rows, 'lines' => $lines];
    }

    protected function isEmptyRow(array $row): bool
    {
        foreach ($row as $value) {
            if ($value !== null && trim((string)$value) !== '') {
                return false;
            }
        }

        return true;
    }

    // ------------------------------------------------------------------ fields and mapping

    /**
     * Fields a column can be mapped to.
     *
     * @return array<int, array{id: string, name: string, required: bool, hint: string}>
     */
    public function fields(): array
    {
        $required = ['entry_date', 'account_code', 'amount'];
        $ids = ['entry_date', 'account_code', 'amount', 'kind', 'entity', 'region', 'client_segment', 'product_group', 'cost_center', 'counterparty_name', 'document_no', 'description', 'currency'];

        return array_map(fn($id) => [
            'id' => $id,
            'name' => __('databackd-data-common.fields.' . $id),
            'required' => in_array($id, $required, true),
            'hint' => __('databackd-data-common.field_hints.' . $id),
        ], $ids);
    }

    /**
     * Guess the mapping from the file headers (Bulgarian and English synonyms).
     *
     * @return array<string,int> [fieldId => columnIndex]
     */
    public function guessMapping(array $headers): array
    {
        $synonyms = [
            'entry_date' => ['date', 'entrydate', 'дата', 'период', 'period', 'месец', 'month', 'датанадокумент', 'датанаоперация'],
            'account_code' => ['account', 'accountcode', 'сметка', 'кодналсметка', 'коднасметка', 'сметкакод', 'счетоводнасметка', 'код', 'code', 'accountno'],
            'amount' => ['amount', 'сума', 'стойност', 'value', 'оборот', 'turnover', 'sum', 'обороти', 'total', 'сумавeur', 'сумавевро'],
            'kind' => ['kind', 'type', 'вид', 'тип', 'видназаписа', 'видзапис', 'оборотсалдо', 'recordtype'],
            'entity' => ['entity', 'company', 'юридическолице', 'фирма', 'дружество', 'legalentity', 'юрлице'],
            'region' => ['region', 'регион', 'град', 'област', 'city', 'територия'],
            'client_segment' => ['segment', 'clientsegment', 'сегмент', 'видклиент', 'сегментклиенти', 'customersegment', 'типклиент'],
            'product_group' => ['productgroup', 'product', 'продуктоваагрупа', 'продуктовагрупа', 'продукт', 'групапродукти', 'категория', 'category'],
            'cost_center' => ['costcenter', 'costcentre', 'центърнаразходи', 'център', 'отдел', 'department', 'разходенцентър'],
            'counterparty_name' => ['counterparty', 'контрагент', 'клиент', 'доставчик', 'customer', 'supplier', 'vendor'],
            'document_no' => ['document', 'documentno', 'docno', 'документ', 'номер', 'номернадокумент', 'фактура', 'invoice', 'invoiceno'],
            'description' => ['description', 'описание', 'забележка', 'бележка', 'note', 'notes', 'коментар', 'comment'],
            'currency' => ['currency', 'валута', 'curr'],
        ];

        $normalized = [];

        foreach ($headers as $index => $header) {
            $normalized[$index] = $this->normalizeHeader((string)$header);
        }

        $mapping = [];
        $usedColumns = [];

        // exact synonym match first
        foreach ($synonyms as $field => $list) {
            foreach ($normalized as $index => $name) {
                if (isset($usedColumns[$index]) || $name === '') {
                    continue;
                }

                if (in_array($name, $list, true)) {
                    $mapping[$field] = $index;
                    $usedColumns[$index] = true;
                    break;
                }
            }
        }

        // then "header contains synonym" for the fields that are still unmapped
        foreach ($synonyms as $field => $list) {
            if (isset($mapping[$field])) {
                continue;
            }

            foreach ($normalized as $index => $name) {
                if (isset($usedColumns[$index]) || $name === '') {
                    continue;
                }

                foreach ($list as $synonym) {
                    if (mb_strlen($synonym) >= 4 && str_contains($name, $synonym)) {
                        $mapping[$field] = $index;
                        $usedColumns[$index] = true;
                        break 2;
                    }
                }
            }
        }

        return $mapping;
    }

    /**
     * AFV (S12): signature of a header row - files with the same columns (in the same order) get the same one; used to
     * offer the mapping of an earlier import of such a file.
     */
    public function headersSignature(array $headers): string
    {
        return sha1(implode('|', array_map(fn($header) => $this->normalizeHeader((string)$header), array_values($headers))));
    }

    protected function normalizeHeader(string $header): string
    {
        return preg_replace('/[^\p{L}\p{N}]+/u', '', mb_strtolower(trim($header)));
    }

    /**
     * Form format [columnIndex => fieldId|''] to canonical [fieldId => columnIndex]. Empty fields are skipped; a field mapped twice keeps the first column.
     */
    public function mappingFromColumns(array $columns): array
    {
        $mapping = [];
        $known = array_column($this->fields(), 'id');

        foreach ($columns as $index => $field) {
            // posted form data: only known field ids on numeric column indexes (an array value would be a TypeError below)
            if (!is_string($field) || !in_array($field, $known, true) || !is_numeric($index) || $index < 0) {
                continue;
            }

            if (!isset($mapping[$field])) {
                $mapping[$field] = (int)$index;
            }
        }

        return $mapping;
    }

    /**
     * Canonical mapping to the per-column form format: array of length $columnCount with the field id (or '') for every column.
     */
    public function columnsFromMapping(array $mapping, int $columnCount): array
    {
        $columns = array_fill(0, $columnCount, '');

        foreach ($mapping as $field => $index) {
            if (is_numeric($index) && $index >= 0 && $index < $columnCount) {
                $columns[(int)$index] = $field;
            }
        }

        return $columns;
    }

    /**
     * Names of the required fields that are not mapped.
     *
     * @return array<int,string> field ids
     */
    public function missingRequired(array $mapping): array
    {
        $missing = [];

        foreach ($this->fields() as $field) {
            if ($field['required'] && !isset($mapping[$field['id']])) {
                $missing[] = $field['id'];
            }
        }

        return $missing;
    }

    // ------------------------------------------------------------------ validation

    /**
     * Validate and normalize one row.
     *
     * $options: entity_id (default юр. лице), create_missing_dimensions (bool), currency (default currency code).
     * Dimensions that do not exist yet and are allowed to be created are returned in `_new_dimensions` (import() creates them),
     * so previews never write to the database.
     *
     * @return array{0: array|null, 1: array<int,string>} [normalizedData|null, errors]
     */
    public function validateRow(array $row, array $mapping, array $options = []): array
    {
        $errors = [];
        $get = function (string $field) use ($row, $mapping) {
            if (!isset($mapping[$field])) {
                return null;
            }

            $value = $row[$mapping[$field]] ?? null;

            if (is_string($value)) {
                $value = trim($value);
            }

            return ($value === '' ? null : $value);
        };

        $data = ['_new_dimensions' => []];

        // date
        $rawDate = $get('entry_date');
        $date = $rawDate === null ? null : $this->parseDate($rawDate);

        if ($rawDate === null) {
            $errors[] = __('databackd-data-common.errors.date_required');
        }
        elseif ($date === null) {
            $errors[] = __('databackd-data-common.errors.date_invalid', ['value' => $this->display($rawDate)]);
        }
        else {
            $data['entry_date'] = $date;
        }

        // account
        $rawAccount = $get('account_code');
        $account = null;

        if ($rawAccount === null) {
            $errors[] = __('databackd-data-common.errors.account_required');
        }
        else {
            $account = $this->findAccount($rawAccount);

            if (!$account) {
                $errors[] = __('databackd-data-common.errors.account_unknown', ['value' => $this->display($rawAccount)]);
            }
            else {
                $data['account_id'] = $account['id'];
            }
        }

        // amount
        $rawAmount = $get('amount');
        $amount = null;

        if ($rawAmount === null) {
            $errors[] = __('databackd-data-common.errors.amount_required');
        }
        else {
            $amount = $this->parseAmount($rawAmount, $options['decimals'][$mapping['amount']] ?? null); // databackd: decimal separator of the column

            if ($amount === null) {
                $errors[] = __('databackd-data-common.errors.amount_invalid', ['value' => $this->display($rawAmount)]);
            }
            elseif ($amount <= 0) {
                $errors[] = __('databackd-data-common.errors.amount_positive', ['value' => $this->display($rawAmount)]);
                $amount = null;
            }
            elseif (!is_finite($amount) || $amount > self::MAX_AMOUNT) {
                // fin_entries.amount is decimal(15,2): a bigger / infinite value would be silently clamped by MySQL (non-strict mode)
                $errors[] = __('databackd-data-common.errors.amount_invalid', ['value' => $this->display($rawAmount)]);
                $amount = null;
            }
            else {
                $data['amount'] = $amount;
            }
        }

        // kind: explicit value, otherwise balance for balance-sheet accounts and flow for P&L accounts
        $rawKind = $get('kind');

        if ($rawKind !== null) {
            $kind = $this->parseKind((string)$rawKind);

            if ($kind === null) {
                $errors[] = __('databackd-data-common.errors.kind_invalid', ['value' => $this->display($rawKind)]);
            }
            else {
                $data['kind'] = $kind;
            }
        }
        elseif ($account) {
            $data['kind'] = in_array($account['type'], ['asset', 'liability', 'equity'], true) ? 'balance' : 'flow';
        }
        else {
            $data['kind'] = 'flow';
        }

        // currency
        $rawCurrency = $get('currency');
        // without a currency column the currency written in the amount itself ("99,50 лв.") wins over the default:
        // since 2026-01-01 the default is EUR and a leva amount must be converted at the fixed rate, not taken as euro
        $currency = $this->parseCurrency($rawCurrency) ?? $this->amountCurrency($rawAmount) ?? ($options['currency'] ?? $this->baseCurrency());

        if ($rawCurrency !== null && $this->parseCurrency($rawCurrency) === null) {
            $errors[] = __('databackd-data-common.errors.currency_invalid', ['value' => $this->display($rawCurrency)]);
        }

        $data['currency'] = $currency;

        // AFV (S12): a currency without any known rate (e.g. "XXX" or USD without a rate) used to be stored 1:1 as the base
        // currency - silently wrong figures in every report. The row is rejected with the reason instead.
        if ($amount !== null && $this->baseFactor($currency, $data['entry_date'] ?? null) === null) {
            $errors[] = __('databackd-data-import.row_errors.currency_no_rate', [
                'currency' => $currency,
                'date' => isset($data['entry_date']) ? Carbon::parse($data['entry_date'])->format('d.m.Y') : '—',
            ]);
            $amount = null;
        }

        if ($amount !== null) {
            $data['amount_base'] = $this->toBase($amount, $currency, $data['entry_date'] ?? null); // AFV (S8): the rate of the entry date

            // AFV (S6/P6): amount_base is decimal(15,2); a tiny rate must not overflow (and be clamped) silently
            if (abs($data['amount_base']) > self::MAX_AMOUNT_BASE) {
                $errors[] = __('databackd-data-common.errors.amount_invalid', ['value' => $this->display($rawAmount)]);
            }
        }

        // legal entity
        $rawEntity = $get('entity');

        if ($rawEntity !== null) {
            $entityId = $this->findEntity((string)$rawEntity);

            if (!$entityId) {
                $errors[] = __('databackd-data-common.errors.entity_unknown', ['value' => $this->display($rawEntity)]);
            }

            $data['entity_id'] = $entityId;
        }
        else {
            $data['entity_id'] = !empty($options['entity_id']) ? (int)$options['entity_id'] : null;
        }

        // dimensions
        $create = !empty($options['create_missing_dimensions']);

        foreach (['region' => 'region_id', 'client_segment' => 'client_segment_id', 'product_group' => 'product_group_id', 'cost_center' => 'cost_center_id'] as $type => $column) {
            $name = $get($type);
            $data[$column] = null;

            if ($name === null) {
                continue;
            }

            $name = mb_substr((string)$name, 0, 191);
            $id = $this->findDimension($type, $name);

            if ($id) {
                $data[$column] = $id;
            }
            elseif ($create) {
                $data['_new_dimensions'][$column] = ['type' => $type, 'name' => $name];
            }
            else {
                $errors[] = __('databackd-data-common.errors.dimension_unknown', ['type' => __('databackd-data-common.fields.' . $type), 'value' => $name]);
            }
        }

        // free text
        // fin_entries.counterparty_name / description are varchar(191), document_no varchar(60)
        $data['counterparty_name'] = $get('counterparty_name') !== null ? mb_substr((string)$get('counterparty_name'), 0, 191) : null;
        $data['document_no'] = $get('document_no') !== null ? mb_substr((string)$this->plainNumber($get('document_no')), 0, 60) : null;
        $data['description'] = $get('description') !== null ? mb_substr((string)$get('description'), 0, 191) : null;

        return [$errors ? null : $data, $errors];
    }

    /**
     * Validate the first rows for a preview.
     *
     * @return array<int, array{row: int, raw: array, data: array|null, errors: array<int,string>}> `row` is the row number in the file (header = 1)
     */
    public function preview(array $rows, array $mapping, array $options = [], int $limit = 10): array
    {
        $result = [];
        $options['decimals'] ??= $this->detectDecimalSeparators($rows);

        foreach (array_slice($rows, 0, $limit, true) as $i => $row) {
            [$data, $errors] = $this->validateRow($row, $mapping, $options);
            $result[] = ['row' => $this->rowNumber($options, $i), 'raw' => $row, 'data' => $data, 'errors' => $errors];
        }

        return $result;
    }

    // ------------------------------------------------------------------ import / undo

    /**
     * Import rows into fin_entries and keep the fin_import_batches record up to date.
     *
     * $options:
     *  - entity_id, source (excel|google_sheets|csv|api|manual...), file_name, source_url, create_missing_dimensions, user_id
     *  - batch_id: continue an existing batch (chunked imports); counters are added to it
     *  - row_offset: number of data rows already processed in earlier chunks (for error row numbers)
     *  - total_rows: expected total (sets rows_total on the first call; with batch_id it is kept)
     *  - finalize (default true): set the final status; pass false for every chunk except the last
     *  - decimals: decimal separator per column (detectDecimalSeparators() of ALL rows); detected from $rows when missing
     *  - lines: physical row numbers of $rows (readFile()['lines'], sliced like the chunk) - used for the error row numbers (AFV S12)
     *  - keep_values (bool): store the cells of a failed row with its error, for the "rows with errors" download (AFV S12)
     *  - batch_meta: extra keys stored with the mapping of a new batch (`_headers`, `_headers_sig`, `_file_sha256`; AFV S12)
     *
     * @return FinImportBatch
     */
    public function import(array $rows, array $mapping, array $options = []): FinImportBatch
    {
        if (!empty($options['batch_id'])) {
            $batch = FinImportBatch::withoutGlobalScopes()->where('company_id', $this->companyId)->findOrFail($options['batch_id']);
        }
        else {
            $batch = new FinImportBatch();
            $batch->company_id = $this->companyId;
            $batch->source = $options['source'] ?? 'excel';
            $batch->file_name = $options['file_name'] ?? null;
            $batch->source_url = $options['source_url'] ?? null;
            $batch->mapping = $mapping + (array)($options['batch_meta'] ?? []);
            $batch->rows_total = (int)($options['total_rows'] ?? 0);
            $batch->created_by = $options['user_id'] ?? $this->currentUserId();
        }

        $source = $batch->source;
        $userId = $batch->created_by;
        $batch->status = self::STATUS_PROCESSING;
        $batch->save();

        // chunked imports pass the separators of the whole file, so that every chunk reads a column the same way
        $options['decimals'] ??= $this->detectDecimalSeparators($rows);
        $offset = (int)($options['row_offset'] ?? 0);
        $errors = $batch->errors ?: [];
        $imported = 0;
        $failed = 0;
        $processed = 0;
        $pending = [];
        $now = now();

        foreach ($rows as $i => $row) {
            if ($this->isEmptyRow($row)) {
                continue;
            }

            $processed++;
            [$data, $rowErrors] = $this->validateRow($row, $mapping, $options);

            if ($data === null) {
                $failed++;

                if (count($errors) < self::MAX_STORED_ERRORS) {
                    $error = ['row' => $this->rowNumber($options, $i, $offset), 'messages' => $rowErrors];

                    if (!empty($options['keep_values'])) {
                        $error['values'] = $this->errorValues($row);
                    }

                    $errors[] = $error;
                }

                continue;
            }

            foreach ($data['_new_dimensions'] as $column => $dimension) {
                $data[$column] = $this->createDimension($dimension['type'], $dimension['name']);
            }

            unset($data['_new_dimensions']);

            $pending[] = $data + [
                'company_id' => $this->companyId,
                'client_id' => null,
                'source' => $source,
                'import_batch_id' => $batch->id,
                'created_by' => $userId,
                'created_at' => $now,
                'updated_at' => $now,
            ];
            $imported++;

            if (count($pending) >= 500) {
                $this->flush($pending);
            }
        }

        $this->flush($pending);

        if (empty($options['batch_id']) && empty($options['total_rows'])) {
            $batch->rows_total = $processed;
        }
        elseif (!empty($options['batch_id']) && empty($options['total_rows']) && $batch->rows_total < $batch->rows_imported + $batch->rows_failed + $processed) {
            $batch->rows_total = $batch->rows_imported + $batch->rows_failed + $processed;
        }

        $batch->rows_imported += $imported;
        $batch->rows_failed += $failed;
        $batch->errors = $errors ?: null;

        if ($options['finalize'] ?? true) {
            $batch->status = $this->finalStatus($batch);
        }

        $batch->save();

        return $batch;
    }

    /**
     * Import associative records ([['entry_date' => ..., 'account_code' => ..., 'amount' => ...], ...]) – used by the REST API.
     * Keys are the field ids of fields(); unknown keys are ignored.
     */
    public function importRecords(array $records, array $options = []): FinImportBatch
    {
        $fieldIds = array_column($this->fields(), 'id');
        $mapping = array_flip($fieldIds);
        $rows = [];
        $structural = []; // 0-based position => messages (records that are not usable objects)

        foreach (array_values($records) as $i => $record) {
            $messages = $this->recordStructureErrors($record, $fieldIds);

            if ($messages) {
                $structural[$i] = $messages;
                $rows[] = array_fill(0, count($fieldIds), null); // an empty row: import() skips it, the error is added below
                continue;
            }

            $rows[] = array_map(fn($id) => $record[$id] ?? null, $fieldIds);
        }

        $batch = $this->import($rows, $mapping, $options + ['source' => 'api']);

        if ($structural) {
            $errors = $batch->errors ?: [];

            foreach ($structural as $i => $messages) {
                if (count($errors) < self::MAX_STORED_ERRORS) {
                    $errors[] = ['row' => $i + 2, 'messages' => $messages];
                }
            }

            usort($errors, fn($a, $b) => $a['row'] <=> $b['row']);

            $batch->rows_failed += count($structural);
            $batch->rows_total += count($structural);
            $batch->errors = $errors;
            $batch->status = $this->finalStatus($batch);
            $batch->save();
        }

        return $batch;
    }

    /**
     * API records must be JSON objects whose known fields are strings, numbers or null (a nested array / object / boolean in a field
     * would otherwise crash the parsers). Unknown keys are ignored (they are never read).
     *
     * @return array<int,string> error messages (empty = usable)
     */
    protected function recordStructureErrors(mixed $record, array $fieldIds): array
    {
        // AFV (S12): the messages are translated like the other row errors (the company / user locale)
        if (!is_array($record) || ($record && array_is_list($record))) {
            return [__('databackd-data-import.row_errors.record_not_object')];
        }

        $errors = [];
        $hasValue = false;

        foreach ($fieldIds as $id) {
            if (!array_key_exists($id, $record) || $record[$id] === null) {
                continue;
            }

            $value = $record[$id];

            if (!is_string($value) && !is_int($value) && !is_float($value)) {
                $errors[] = __('databackd-data-import.row_errors.field_type', ['field' => $id]);
            }
            elseif (is_string($value) && mb_strlen($value) > 2000) {
                $errors[] = __('databackd-data-import.row_errors.field_too_long', ['field' => $id, 'max' => 2000]);
            }
            elseif (is_string($value) ? trim($value) !== '' : true) {
                $hasValue = true;
            }
        }

        if (!$errors && !$hasValue) {
            $errors[] = __('databackd-data-import.row_errors.record_empty');
        }

        return $errors;
    }

    /**
     * Delete all entries of an import batch and mark it as undone.
     *
     * @return int number of deleted entries
     */
    public function undo(FinImportBatch $batch): int
    {
        if ((int)$batch->company_id !== $this->companyId) {
            throw new \InvalidArgumentException('The batch belongs to another company.');
        }

        return DB::transaction(function () use ($batch) {
            $deleted = FinEntry::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('import_batch_id', $batch->id)
                ->delete();

            // AFV (S8): the header of an imported bank statement goes with its entries
            \App\Models\Databackd\FinBankStatement::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->where('import_batch_id', $batch->id)
                ->delete();

            $batch->status = self::STATUS_UNDONE;
            $batch->save();

            return $deleted;
        });
    }

    /**
     * AFV (S12): row number of the data row $index for messages - its physical row in the file when known (options `lines`),
     * otherwise its position + 2 (header = row 1).
     */
    public function rowNumber(array $options, int $index, int $offset = 0): int
    {
        return isset($options['lines'][$index]) ? (int)$options['lines'][$index] : $offset + $index + 2;
    }

    /**
     * AFV (S12): the cells of a failed row as stored with its error (bounded: MAX_ERROR_VALUE_COLUMNS cells of up to 255 characters).
     *
     * @return array<int,string|null>
     */
    protected function errorValues(array $row): array
    {
        return array_map(
            fn($value) => $value === null ? null : mb_substr(is_scalar($value) ? (string)$value : json_encode($value), 0, 255),
            array_slice(array_values($row), 0, self::MAX_ERROR_VALUE_COLUMNS)
        );
    }

    /**
     * AFV (S12): which of the given rows already exist as entries (same date, account, amount, legal entity and kind) - a file or
     * sheet that is imported a second time would double the figures. Only the first $limit valid rows are checked (one query).
     *
     * @return array{checked: int, found: int, batches: array<int, array{id: int, created_at: ?string, file_name: ?string, source: string}>}
     */
    public function existingMatches(array $rows, array $mapping, array $options = [], int $limit = 50): array
    {
        $options['decimals'] ??= $this->detectDecimalSeparators($rows);
        $candidates = [];

        foreach (array_slice($rows, 0, $limit) as $row) {
            [$data] = $this->validateRow($row, $mapping, $options);

            if ($data !== null) {
                $candidates[] = $data;
            }
        }

        $result = ['checked' => count($candidates), 'found' => 0, 'batches' => []];

        if (!$candidates) {
            return $result;
        }

        $key = fn($date, $account, $amount, $entity, $kind) => implode('|', [substr((string)$date, 0, 10), (int)$account, number_format((float)$amount, 2, '.', ''), (int)$entity, (string)$kind]);

        $existing = FinEntry::withoutGlobalScopes()
            ->where('company_id', $this->companyId)
            ->whereIn('entry_date', array_values(array_unique(array_column($candidates, 'entry_date'))))
            ->whereIn('account_id', array_values(array_unique(array_column($candidates, 'account_id'))))
            ->whereIn('amount', array_values(array_unique(array_map(fn($c) => round((float)$c['amount'], 2), $candidates))))
            ->limit(5000)
            ->get(['entry_date', 'account_id', 'amount', 'entity_id', 'kind', 'import_batch_id']);

        $index = [];

        foreach ($existing as $entry) {
            $index[$key($entry->entry_date instanceof \DateTimeInterface ? $entry->entry_date->format('Y-m-d') : $entry->entry_date, $entry->account_id, $entry->amount, $entry->entity_id, $entry->kind)] = $entry->import_batch_id ?: 0;
        }

        $batchIds = [];

        foreach ($candidates as $data) {
            $hit = $index[$key($data['entry_date'], $data['account_id'], $data['amount'], $data['entity_id'] ?? null, $data['kind'] ?? null)] ?? null;

            if ($hit !== null) {
                $result['found']++;

                if ($hit) {
                    $batchIds[$hit] = true;
                }
            }
        }

        if ($batchIds) {
            $result['batches'] = FinImportBatch::withoutGlobalScopes()
                ->where('company_id', $this->companyId)
                ->whereIn('id', array_keys($batchIds))
                ->orderByDesc('id')
                ->limit(3)
                ->get(['id', 'created_at', 'file_name', 'source'])
                ->map(fn($batch) => [
                    'id' => (int)$batch->id,
                    'created_at' => $batch->created_at?->timezone($this->company()->timezone ?? config('app.timezone'))->format('d.m.Y H:i'),
                    'file_name' => $batch->file_name,
                    'source' => (string)$batch->source,
                ])->all();
        }

        return $result;
    }

    public function finalStatus(FinImportBatch $batch): string
    {
        if ($batch->rows_imported === 0) {
            return self::STATUS_FAILED;
        }

        return $batch->rows_failed > 0 ? self::STATUS_PARTIAL : self::STATUS_COMPLETED;
    }

    protected function flush(array &$pending): void
    {
        if ($pending) {
            DB::table('fin_entries')->insert($pending);
            $pending = [];
        }
    }

    protected function currentUserId(): ?int
    {
        try {
            return auth()->check() ? user()?->id : null;
        } catch (\Throwable $e) {
            return null;
        }
    }

    // ------------------------------------------------------------------ parsing helpers

    protected function display(mixed $value): string
    {
        return mb_substr(is_scalar($value) ? (string)$value : json_encode($value), 0, 60);
    }

    /** Excel stores codes/numbers as floats: 501.0 -> "501". */
    protected function plainNumber(mixed $value): string
    {
        if (is_float($value) && floor($value) == $value) {
            return (string)(int)$value;
        }

        return trim((string)$value);
    }

    /**
     * Dates: Excel serial numbers, d.m.Y, d/m/Y, d-m-Y, Y-m-d, Y/m/d, d.m.y, month only (Y-m, m.Y => last day of the month).
     *
     * @return string|null Y-m-d
     */
    public function parseDate(mixed $value): ?string
    {
        if ($value instanceof \DateTimeInterface) {
            return Carbon::instance($value)->format('Y-m-d');
        }

        if ($value === null || $value === '') {
            return null;
        }

        $text = trim((string)$value);

        // Excel serial (days since 1899-12-30)
        if (preg_match('/^\d{4,6}(\.\d+)?$/', $text) && (float)$text > 20000 && (float)$text < 80000) {
            return Carbon::create(1899, 12, 30)->addDays((int)floor((float)$text))->format('Y-m-d');
        }

        $text = preg_replace('/\s*(г\.|г)$/u', '', $text);
        $text = trim(explode(' ', $text)[0]);
        $text = explode('T', $text)[0];

        if (preg_match('/^(\d{4})[-\/.](\d{1,2})[-\/.](\d{1,2})$/', $text, $m)) {
            return $this->makeDate((int)$m[1], (int)$m[2], (int)$m[3]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})$/', $text, $m)) {
            return $this->makeDate((int)$m[3], (int)$m[2], (int)$m[1]);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{2})$/', $text, $m)) {
            return $this->makeDate(2000 + (int)$m[3], (int)$m[2], (int)$m[1]);
        }

        // month only
        if (preg_match('/^(\d{4})[-\/.](\d{1,2})$/', $text, $m)) {
            return $this->makeDate((int)$m[1], (int)$m[2], null);
        }

        if (preg_match('/^(\d{1,2})[-\/.](\d{4})$/', $text, $m)) {
            return $this->makeDate((int)$m[2], (int)$m[1], null);
        }

        return null;
    }

    protected function makeDate(int $year, int $month, ?int $day): ?string
    {
        if ($year < 1990 || $year > 2100 || $month < 1 || $month > 12) {
            return null;
        }

        if ($day === null) {
            return Carbon::create($year, $month, 1)->endOfMonth()->format('Y-m-d');
        }

        return checkdate($month, $day, $year) ? sprintf('%04d-%02d-%02d', $year, $month, $day) : null;
    }

    /**
     * Amounts with "," or "." as decimal separator, spaces/nbsp as thousands, optional currency text; "(1 234,50)" is negative.
     *
     * $decimal is the decimal separator of the column (detectDecimalSeparators()). With it, a value holding only the other
     * separator is read as thousands ("1.234" with "," => 1234) and only the decimal one as decimal ("1,234" with "," =>
     * 1.23); the other separator is stripped only when it forms thousand groups, otherwise it is read as decimal ("12.50"
     * in a "," column => 12.5). Without it (or when a value has both), the later separator is the decimal one, a single
     * "." is decimal and a single "," followed by exactly three digits is thousands ("1,234" => 1234, "1.234" => 1.23).
     */
    public function parseAmount(mixed $value, ?string $decimal = null): ?float
    {
        if (is_int($value) || is_float($value)) {
            return round((float)$value, 2);
        }

        $parsed = $this->amountText($value);

        if ($parsed === null) {
            return null;
        }

        [$text, $negative, $spaced] = $parsed;
        $lastComma = strrpos($text, ',');
        $lastDot = strrpos($text, '.');

        if ($lastComma !== false && $lastDot !== false) {
            // the later separator is the decimal one
            if ($lastComma > $lastDot) {
                $text = str_replace('.', '', $text);
                $text = str_replace(',', '.', $text);
            }
            else {
                $text = str_replace(',', '', $text);
            }
        }
        elseif ($lastComma !== false || $lastDot !== false) {
            $separator = $lastComma !== false ? ',' : '.';
            $count = substr_count($text, $separator);

            if ($decimal !== null && $separator !== $decimal) {
                // the column's decimal mark is the other one: strip this separator only when it forms thousand groups
                // ("1.234" / "1.234.567" in a "," column); "12.50" and "0.125" there are still decimal values, never 1250 / 125
                if (preg_match('/^-?[1-9]\d{0,2}(' . preg_quote($separator, '/') . '\d{3})+$/', $text)) {
                    $text = str_replace($separator, '', $text);
                }
                elseif ($count > 1) {
                    return null;
                }
            }
            elseif ($decimal !== null) {
                // the decided decimal mark of the column may occur only once
                if ($count > 1) {
                    return null;
                }
            }
            elseif ($separator === ',' ? ($count > 1 || (!$spaced && preg_match('/,\d{3}$/', $text))) : $count > 1) {
                // no column hint (unchanged rule): "1,234" / "1,234,567" / "1.234.567" => thousands, a single "." and
                // "12,5" / "12,50" => decimal; next to space-grouped thousands a single "," is decimal ("1 234,567")
                $text = str_replace($separator, '', $text);
            }

            $text = str_replace(',', '.', $text);
        }

        if (!is_numeric($text)) {
            return null;
        }

        $number = round((float)$text, 2);

        return $negative ? -$number : $number;
    }

    /**
     * Amount text without currency, spaces and sign: [digits with , and ., negative, had space-grouped thousands] or null.
     */
    protected function amountText(mixed $value): ?array
    {
        $text = trim((string)$value);

        if ($text === '') {
            return null;
        }

        $negative = false;

        if (preg_match('/^\((.*)\)$/', $text, $m)) {
            $negative = true;
            $text = $m[1];
        }

        $text = trim(preg_replace(self::AMOUNT_CURRENCY_PATTERN, '', $text));
        $spaced = (bool)preg_match('/\d[\s\x{00A0}\x{202F}\']\d{3}(?!\d)/u', $text);
        $text = preg_replace('/[\s\x{00A0}\x{202F}\']/u', '', $text);

        if (str_starts_with($text, '-')) {
            $negative = !$negative;
            $text = substr($text, 1);
        }
        elseif (str_starts_with($text, '+')) {
            $text = substr($text, 1);
        }

        if ($text === '' || !preg_match('/^[\d.,]+$/', $text)) {
            return null;
        }

        return [$text, $negative, $spaced];
    }

    /**
     * Decimal separator of every column, decided from all its text values (Excel numbers are skipped): "1 234,56",
     * "12,5" or "1.234,56" vote for ",", "1,234.56", "12.5" or "1,234,567" for "."; "1,234" alone is ambiguous and does
     * not vote. The separator with more votes wins, a tie gives null (parseAmount() then reads each value on its own).
     * Google Sheets / CSV exports hold the displayed, locale-dependent text, so one file is consistent within a column.
     *
     * @return array<int, string|null> [columnIndex => "," | "." | null]
     */
    public function detectDecimalSeparators(array $rows): array
    {
        $votes = [];

        foreach ($rows as $row) {
            foreach ((array)$row as $index => $value) {
                if (!is_string($value) || ($parsed = $this->amountText($value)) === null) {
                    continue;
                }

                [$text, , $spaced] = $parsed;
                $commas = substr_count($text, ',');
                $dots = substr_count($text, '.');
                $vote = null;

                if ($commas && $dots) {
                    $vote = strrpos($text, ',') > strrpos($text, '.') ? ',' : '.';
                }
                elseif ($commas + $dots === 1) {
                    $separator = $commas ? ',' : '.';

                    // a single separator is decimal unless exactly three digits follow it (then ambiguous) - but next to
                    // space-grouped thousands ("1 234,567") it can only be the decimal one
                    if ($spaced || !preg_match('/[.,]\d{3}$/', $text)) {
                        $vote = $separator;
                    }
                }
                elseif ($commas > 1 || $dots > 1) {
                    $vote = $commas ? '.' : ','; // repeated separator = thousands, so the decimal one is the other
                }

                if ($vote !== null) {
                    $votes[$index][$vote] = ($votes[$index][$vote] ?? 0) + 1;
                }
            }
        }

        $result = [];

        foreach ($votes as $index => $count) {
            $comma = $count[','] ?? 0;
            $dot = $count['.'] ?? 0;
            $result[$index] = $comma === $dot ? null : ($comma > $dot ? ',' : '.');
        }

        return $result;
    }

    /**
     * Currency code written inside an amount cell ("1 234,50 лв." -> BGN, "€10" -> EUR), or null.
     */
    protected function amountCurrency(mixed $value): ?string
    {
        if (!is_string($value) || !preg_match(self::AMOUNT_CURRENCY_PATTERN, $value, $m)) {
            return null;
        }

        $marker = mb_strtolower($m[1]);

        return match (true) {
            in_array($marker, ['лева', 'лв', 'лв.', 'bgn'], true) => 'BGN',
            in_array($marker, ['евро', '€', 'eur'], true) => 'EUR',
            in_array($marker, ['usd', '$'], true) => 'USD',
            default => 'GBP',
        };
    }

        protected function parseKind(string $value): ?string
    {
        $value = mb_strtolower(trim($value));

        if (in_array($value, ['flow', 'turnover', 'оборот', 'обороти', 'обор', 'поток'], true)) {
            return 'flow';
        }

        if (in_array($value, ['balance', 'салдо', 'баланс', 'сал', 'bal', 'stock'], true)) {
            return 'balance';
        }

        return null;
    }

    protected function parseCurrency(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        $text = mb_strtoupper(trim((string)$value));
        $text = str_replace(['€', 'ЛВ.', 'ЛВ', 'EURO', 'ЕВРО'], ['EUR', 'BGN', 'BGN', 'EUR', 'EUR'], $text);

        return preg_match('/^[A-Z]{3}$/', $text) ? $text : null;
    }

    // ------------------------------------------------------------------ lookups

    protected function company(): Company
    {
        if ($this->company === null) {
            $company = Company::withoutGlobalScopes()->findOrFail($this->companyId);

            // S6/P1: the currency is read with an explicit company_id. ->with('currency') goes through CompanyScope of the LOGGED-IN user,
            // and a chunk job can run inside the request of a user of ANOTHER company (FinImportController::progress() runs queue:work):
            // the relation would be null and the base currency would silently become EUR.
            $company->setRelation('currency', Currency::withoutGlobalScopes()->where('company_id', $this->companyId)->find($company->currency_id));
            $this->company = $company;
        }

        return $this->company;
    }

    protected function baseCurrency(): string
    {
        return $this->baseCurrency ??= ($this->company()->currency->currency_code ?? 'EUR');
    }

    /**
     * Amount in the company base currency. Order: same currency (as is) -> the fixed BGN/EUR peg -> AFV (S8): the
     * rate of the entry date from fin_fx_rates (FxRateService::rate(): nearest earlier date, manual or ECB, within its
     * age limit) -> the single AFV company rate (currencies.exchange_rate) -> unchanged amount (old behaviour:
     * a currency without any known rate is taken at 1:1).
     *
     * @param string|null $date entry date (Y-m-d); without a date the dated rates are not used
     */
    public function toBase(float $amount, string $currency, ?string $date = null): float
    {
        $factor = $this->baseFactor($currency, $date);

        // a currency without any known rate is kept 1:1 here (other callers: manual entry, invoice sync); the import itself
        // rejects such a row (validateRow(), AFV S12)
        return round($factor === null ? $amount : $amount * $factor, 2);
    }

    /**
     * AFV (S12): units of the base currency for 1 unit of $currency on $date (same order as toBase()), or null when no rate is known.
     */
    public function baseFactor(string $currency, ?string $date = null): ?float
    {
        $base = $this->baseCurrency();

        if ($currency === $base) {
            return 1.0;
        }

        if ($currency === 'BGN' && $base === 'EUR') {
            return 1 / self::BGN_PER_EUR;
        }

        if ($currency === 'EUR' && $base === 'BGN') {
            return self::BGN_PER_EUR;
        }

        // AFV (S8): units of $currency for 1 unit of the base currency on the date of the entry
        if ($date !== null) {
            $dated = ($this->fxRates ??= new FxRateService($this->companyId))->rate($currency, $date, $base);

            if ($dated !== null && $dated > 0) {
                return 1 / $dated;
            }
        }

        if (!array_key_exists($currency, $this->currencyRates)) {
            $this->currencyRates[$currency] = Currency::withoutGlobalScopes()
                ->where('company_id', $this->companyId)->where('currency_code', $currency)->value('exchange_rate');
        }

        $rate = (float)$this->currencyRates[$currency];

        // AFV (S10): AFV exchange_rate = units of the base currency for 1 unit of this currency (USD_EUR)
        return $rate > 0 ? $rate : null;
    }

    protected function loadAccounts(): void
    {
        if ($this->accountsByCode !== null) {
            return;
        }

        $this->accountsByCode = [];
        $this->accountsByName = [];

        FinAccount::withoutGlobalScopes()->where('company_id', $this->companyId)->get(['id', 'code', 'name', 'type'])
            ->each(function ($account) {
                $row = ['id' => $account->id, 'type' => $account->type];
                $this->accountsByCode[(string)$account->code] = $row;
                $this->accountsByName[mb_strtolower($account->name)] = $row;
            });
    }

    protected function findAccount(mixed $value): ?array
    {
        $this->loadAccounts();
        $code = $this->plainNumber($value);

        return $this->accountsByCode[$code] ?? $this->accountsByName[mb_strtolower($code)] ?? null;
    }

    protected function findEntity(string $name): ?int
    {
        if ($this->entitiesByName === null) {
            $this->entitiesByName = [];

            FinEntity::withoutGlobalScopes()->where('company_id', $this->companyId)->get(['id', 'name', 'eik'])
                ->each(function ($entity) {
                    $this->entitiesByName[mb_strtolower($entity->name)] = $entity->id;

                    if ($entity->eik) {
                        $this->entitiesByName[mb_strtolower($entity->eik)] = $entity->id;
                    }
                });
        }

        return $this->entitiesByName[mb_strtolower(trim($name))] ?? null;
    }

    protected function findDimension(string $type, string $name): ?int
    {
        if ($this->dimensions === null) {
            $this->dimensions = [];

            FinDimension::withoutGlobalScopes()->where('company_id', $this->companyId)->get(['id', 'type', 'name', 'code'])
                ->each(function ($dimension) {
                    $this->dimensions[$dimension->type][mb_strtolower($dimension->name)] = $dimension->id;

                    if ($dimension->code) {
                        $this->dimensions[$dimension->type][mb_strtolower($dimension->code)] ??= $dimension->id;
                    }
                });
        }

        return $this->dimensions[$type][mb_strtolower(trim($name))] ?? null;
    }

    protected function createDimension(string $type, string $name): int
    {
        $existing = $this->findDimension($type, $name);

        if ($existing) {
            return $existing;
        }

        $dimension = new FinDimension();
        $dimension->company_id = $this->companyId;
        $dimension->type = $type;
        $dimension->name = $name;
        $dimension->save();

        $this->dimensions[$type][mb_strtolower($name)] = $dimension->id;

        return $dimension->id;
    }

}

<?php

namespace App\Services\Databackd\Gaps\BankImport;

/**
 * Entry point for bank statement files: size and format checks, encoding, format detection (MT940 / CAMT.053)
 * and delegation to Mt940Parser / Camt053Parser.
 *
 * Result of parseFile() / parseString() (the same for both formats):
 *
 *   [
 *     'format'     => 'mt940' | 'camt053',
 *     'file_hash'  => sha256 of the file content,
 *     'warnings'   => string[],                 // things that were skipped or guessed while reading
 *     'statements' => [                         // a file can hold several statements (accounts / days)
 *       [
 *         'format'          => 'mt940' | 'camt053',
 *         'reference'       => string|null,     // :20: / Stmt/Id
 *         'statement_no'    => string|null,     // :28C: / Stmt/Id | LglSeqNb | ElctrncSeqNb
 *         'account_iban'    => string|null,     // IBAN (or the account number when the file has no IBAN)
 *         'currency'        => 'EUR',
 *         'date_from'       => 'Y-m-d'|null,
 *         'date_to'         => 'Y-m-d'|null,
 *         'opening_balance' => float|null,      // signed: credit +, debit -
 *         'opening_date'    => 'Y-m-d'|null,
 *         'closing_balance' => float|null,
 *         'closing_date'    => 'Y-m-d'|null,
 *         'transactions'    => [
 *           [
 *             'date'              => 'Y-m-d',   // booking date (value date when the file has no booking date)
 *             'value_date'        => 'Y-m-d'|null,
 *             'amount'            => float,     // signed: money in +, money out -
 *             'direction'         => 'credit' | 'debit',
 *             'currency'          => 'EUR',
 *             'counterparty_name' => string|null,
 *             'counterparty_iban' => string|null,
 *             'description'       => string|null,
 *             'reference'         => string|null,   // customer / end-to-end reference
 *             'bank_reference'    => string|null,
 *             'type_code'         => string|null,   // MT940 transaction type / CAMT BkTxCd
 *           ], ...
 *         ],
 *       ], ...
 *     ],
 *   ]
 */
class BankStatementParser
{

    const FORMAT_MT940 = 'mt940';
    const FORMAT_CAMT053 = 'camt053';
    const FORMATS = [self::FORMAT_MT940, self::FORMAT_CAMT053];

    /** Maximum size of an uploaded statement. */
    const MAX_BYTES = 5 * 1024 * 1024;

    /**
     * @throws BankStatementException
     */
    public function parseFile(string $path, ?string $originalName = null): array
    {
        if (!is_file($path) || !is_readable($path)) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.unreadable'));
        }

        $size = filesize($path);

        if ($size !== false && $size > self::MAX_BYTES) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.too_large', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        $content = file_get_contents($path);

        if ($content === false) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.unreadable'));
        }

        return $this->parseString($content, $originalName);
    }

    /**
     * @param string|null $name file name, used only as a hint for the format (the content decides)
     *
     * @throws BankStatementException
     */
    public function parseString(string $content, ?string $name = null): array
    {
        if (strlen($content) > self::MAX_BYTES) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.too_large', ['mb' => self::MAX_BYTES / 1024 / 1024]));
        }

        if (trim(str_replace(["\xEF\xBB\xBF", "\0"], '', $content)) === '') {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.empty_file'));
        }

        $format = $this->detectFormat($content, $name);

        if ($format === null) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.unknown_format'));
        }

        $parser = $format === self::FORMAT_CAMT053 ? new Camt053Parser() : new Mt940Parser();
        $result = $parser->parse($content);

        $statements = array_values(array_filter($result['statements'], function ($statement) {
            return $statement['transactions'] || $statement['opening_balance'] !== null || $statement['closing_balance'] !== null;
        }));

        if (!$statements) {
            throw new BankStatementException(__('databackd-gaps-bank-import.errors.no_data'));
        }

        return [
            'format' => $format,
            'file_hash' => hash('sha256', $content),
            'warnings' => array_values(array_unique($result['warnings'] ?? [])),
            'statements' => $statements,
        ];
    }

    /**
     * 'mt940', 'camt053' or null when the content is neither.
     *
     * @throws BankStatementException for XML that is another ISO 20022 report (camt.052 / camt.054)
     */
    public function detectFormat(string $content, ?string $name = null): ?string
    {
        $head = ltrim(self::stripBom($content));

        // XML: <?xml ... or <Document ...
        if (str_starts_with($head, '<') || str_starts_with($head, "\xFF\xFE") || str_starts_with($head, "\xFE\xFF")) {
            $text = self::toUtf8($content);

            if (stripos($text, 'BkToCstmrStmt') !== false) {
                return self::FORMAT_CAMT053;
            }

            if (stripos($text, 'BkToCstmrAcctRpt') !== false || stripos($text, 'BkToCstmrDbtCdtNtfctn') !== false) {
                throw new BankStatementException(__('databackd-gaps-bank-import.errors.wrong_camt'));
            }

            if (stripos($text, '<Document') !== false || stripos($text, '<?xml') !== false) {
                throw new BankStatementException(__('databackd-gaps-bank-import.errors.unknown_xml'));
            }
        }

        $text = self::toUtf8($content);

        // MT940: tags at the start of a line; :20: plus any of :25: :60F: :60M: :61:
        if (preg_match('/(^|[\r\n])[ \t]*(?:\{4:)?:20:/', $text) && preg_match('/[\r\n]:(25|25P|60F|60M|61|62F|62M):/', "\n" . $text)) {
            return self::FORMAT_MT940;
        }

        // no :20: but the typical body
        if (preg_match('/[\r\n]:60[FM]:/', "\n" . $text) && preg_match('/[\r\n]:62[FM]:/', "\n" . $text)) {
            return self::FORMAT_MT940;
        }

        return null;
    }

    // ------------------------------------------------------------------ text helpers shared by the parsers

    public static function stripBom(string $content): string
    {
        return str_starts_with($content, "\xEF\xBB\xBF") ? substr($content, 3) : $content;
    }

    /**
     * Text of a statement file as UTF-8: BOM, UTF-16, UTF-8, otherwise Windows-1251 (Bulgarian banks) or Windows-1252.
     */
    public static function toUtf8(string $content): string
    {
        if (str_starts_with($content, "\xFF\xFE")) {
            return (string)mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16LE');
        }

        if (str_starts_with($content, "\xFE\xFF")) {
            return (string)mb_convert_encoding(substr($content, 2), 'UTF-8', 'UTF-16BE');
        }

        $content = self::stripBom($content);

        if ($content === '' || mb_check_encoding($content, 'UTF-8')) {
            return $content;
        }

        $cyrillic = (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1251');
        $inCyrillic = preg_match_all('/[\x{0400}-\x{04FF}]/u', $cyrillic);
        $inWords = array_sum(array_map('mb_strlen', preg_match_all('/(?<![A-Za-z])[\x{0400}-\x{04FF}]{2,}(?![A-Za-z])/u', $cyrillic, $m) ? $m[0] : []));

        // AFV (S10): the bytes 0xC0-0xFF are Cyrillic letters in Windows-1251 and accented Latin letters in Windows-1252.
        // Bulgarian text has them as whole words ("Получател"), western text inside a Latin word ("Müller", "Grüße"), so only
        // runs without a Latin letter next to them count. The share of all letters is no test: the MT940 tags, IBANs and
        // amounts are Latin, so a Cyrillic :86: was read as Windows-1252.
        if ($inCyrillic > 0 && $inWords / $inCyrillic >= 0.5) {
            return $cyrillic;
        }

        return (string)mb_convert_encoding($content, 'UTF-8', 'Windows-1252');
    }

    /** "1 234,56" / "1234.56" / "1234," to float (null when it is not a number). */
    public static function number(?string $value): ?float
    {
        if ($value === null) {
            return null;
        }

        $value = str_replace([' ', "\xC2\xA0"], '', trim($value));

        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(',', '.', str_replace('.', '', $value))
                : str_replace(',', '', $value);
        }
        else {
            $value = str_replace(',', '.', $value);
        }

        if (str_ends_with($value, '.')) {
            $value .= '0';
        }

        return is_numeric($value) ? round((float)$value, 2) : null;
    }

}

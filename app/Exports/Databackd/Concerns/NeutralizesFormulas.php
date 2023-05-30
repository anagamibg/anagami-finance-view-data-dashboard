<?php

namespace App\Exports\Databackd\Concerns;

/**
 * CSV/Excel formula-injection guard (S6 security review, package P4).
 *
 * A cell is read as a formula/command purely because of its first character:
 *  - "=" is turned into an actual Excel formula by PhpSpreadsheet's own auto-detection
 *    (Worksheet::fromArray() -> Cell::setValue() -> DefaultValueBinder::dataTypeForValue()), which the exports in
 *    this app all go through (Maatwebsite\Excel FromArray/WithMapping -> Sheet::fromArray()). Excel evaluates such
 *    a formula automatically when the file is opened (e.g. =HYPERLINK(...), legacy DDE, WEBSERVICE()-style exfil).
 *  - "+", "-", "@", a leading tab or carriage return are not turned into a PhpSpreadsheet TYPE_FORMULA cell by
 *    this app's PhpSpreadsheet version, but are still read as "start of a formula/command" by Excel's own
 *    heuristics on a plain CSV download (no cell-type metadata to tell it otherwise - see
 *    FinImportBatchController::errorsExport()) and by other spreadsheet applications (Google Sheets, LibreOffice),
 *    so the same characters are neutralized everywhere for one consistent rule.
 *
 * Every export under app/Exports/Databackd that writes a *user-controlled* string into a cell (counterparty /
 * legal-entity / account / dimension / ratio names, descriptions, document numbers, company name, titles) should
 * run it through neutralizeFormula()/neutralizeRow() right before it is placed into the array handed to
 * PhpSpreadsheet. Only strings are touched - int/float/bool/null all pass through unchanged, so a real negative
 * number (e.g. amount = -20.5) stays a number and is never affected; only a STRING cell can carry a formula.
 */
trait NeutralizesFormulas
{

    /** First characters that make a spreadsheet (or a plain CSV opened in one) treat a cell as a formula/command. */
    private const FORMULA_LEAD_CHARS = ['=', '+', '-', '@', "\t", "\r"];

    /**
     * Prefixes $value with a single apostrophe - the same character Excel's own UI inserts to force "treat as
     * text" - when it would otherwise start with a formula-trigger character, so the cell is written as a literal
     * string and never evaluated as a formula. A value already starting with an apostrophe is left alone (not
     * double-prefixed), after checking what follows it. Any non-string value (number, bool, null, Carbon/DateTime,
     * ...) is returned unchanged.
     */
    public static function neutralizeFormula(mixed $value): mixed
    {
        if (!is_string($value) || $value === '') {
            return $value;
        }

        $toCheck = $value[0] === "'" ? substr($value, 1) : $value;

        if ($toCheck !== '' && in_array($toCheck[0], self::FORMULA_LEAD_CHARS, true)) {
            return "'" . $value;
        }

        return $value;
    }

    /** neutralizeFormula() applied to every value of a flat row (keys preserved). */
    public static function neutralizeRow(array $row): array
    {
        return array_map(static fn($value) => self::neutralizeFormula($value), $row);
    }

}

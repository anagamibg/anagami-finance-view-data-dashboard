<?php

namespace App\Services\Databackd\Gaps\BankImport;

/**
 * IBAN helpers shared by the bank statement parsers and the counterparty matcher.
 */
final class Iban
{

    /** Length of the IBAN per country (the countries a Bulgarian company meets most often). */
    const LENGTHS = [
        'BG' => 22, 'RO' => 24, 'GR' => 27, 'DE' => 22, 'AT' => 20, 'FR' => 27, 'IT' => 27, 'ES' => 24, 'NL' => 18, 'BE' => 16,
        'GB' => 22, 'IE' => 22, 'PL' => 28, 'CZ' => 24, 'SK' => 24, 'HU' => 28, 'HR' => 21, 'SI' => 19, 'RS' => 22, 'MK' => 19,
        'TR' => 26, 'CY' => 28, 'MT' => 31, 'LU' => 20, 'PT' => 25, 'CH' => 21, 'LT' => 20, 'LV' => 21, 'EE' => 20, 'FI' => 18,
        'SE' => 24, 'DK' => 18, 'NO' => 15, 'UA' => 29, 'ME' => 22, 'AL' => 28, 'BA' => 20, 'XK' => 20,
    ];

    /** Upper case, without spaces and separators. */
    public static function normalize(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $value = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $value));

        return $value === '' ? null : $value;
    }

    /** Structure (country + 2 digits + 11..30 alphanumerics) and mod 97 check. */
    public static function isValid(?string $value): bool
    {
        $iban = self::normalize($value);

        if ($iban === null || !preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $iban)) {
            return false;
        }

        $country = substr($iban, 0, 2);

        if (isset(self::LENGTHS[$country]) && strlen($iban) !== self::LENGTHS[$country]) {
            return false;
        }

        $rearranged = substr($iban, 4) . substr($iban, 0, 4);
        $numeric = '';

        foreach (str_split($rearranged) as $char) {
            $numeric .= ctype_alpha($char) ? (string)(ord($char) - 55) : $char;
        }

        $remainder = 0;

        foreach (str_split($numeric, 7) as $chunk) {
            $remainder = (int)($remainder . $chunk) % 97;
        }

        return $remainder === 1;
    }

    /**
     * First valid IBAN found in free text (spaces inside the IBAN are tolerated), skipping $exclude.
     */
    public static function find(?string $text, ?string $exclude = null): ?string
    {
        if ($text === null || $text === '') {
            return null;
        }

        $exclude = self::normalize($exclude);

        // "BG80 BNBG 9661 1020 3456 78" style or compact
        if (!preg_match_all('/\b[A-Z]{2}\d{2}(?:[ ]?[A-Z0-9]){11,30}\b/', strtoupper($text), $matches)) {
            return null;
        }

        foreach ($matches[0] as $candidate) {
            $iban = self::normalize($candidate);

            // a trailing currency/word glued to the IBAN ("...5678EUR"): try the country length
            $country = substr($iban, 0, 2);

            if (isset(self::LENGTHS[$country]) && strlen($iban) > self::LENGTHS[$country]) {
                $iban = substr($iban, 0, self::LENGTHS[$country]);
            }

            if (self::isValid($iban) && $iban !== $exclude) {
                return $iban;
            }
        }

        return null;
    }

    /**
     * The account number of an MT940 :25: field or similar: the IBAN when there is one ("BG80BNBG96611020345678EUR",
     * "BNBGBGSF/BG80BNBG96611020345678"), otherwise the cleaned raw value.
     *
     * @return array{0: string|null, 1: string|null} [iban or account number, currency glued to it]
     */
    public static function fromAccountField(?string $raw): array
    {
        if ($raw === null || trim($raw) === '') {
            return [null, null];
        }

        $parts = array_map('trim', explode('/', trim($raw)));
        $currency = null;

        foreach ($parts as $index => $part) {
            $clean = self::normalize($part);

            if ($clean === null) {
                continue;
            }

            if (preg_match('/^[A-Z]{2}\d{2}[A-Z0-9]{11,30}$/', $clean)) {
                $country = substr($clean, 0, 2);

                if (isset(self::LENGTHS[$country]) && strlen($clean) > self::LENGTHS[$country]) {
                    $suffix = substr($clean, self::LENGTHS[$country]);
                    $clean = substr($clean, 0, self::LENGTHS[$country]);

                    if (preg_match('/^[A-Z]{3}$/', $suffix)) {
                        $currency = $suffix;
                    }
                }

                if (self::isValid($clean) || isset(self::LENGTHS[$country])) {
                    return [$clean, $currency];
                }
            }

            // a plain currency code after the account ("1234567890/EUR")
            if ($index > 0 && preg_match('/^[A-Z]{3}$/', $clean)) {
                $currency = $clean;
            }
        }

        // not an IBAN: keep the first non-empty part as the account number
        foreach ($parts as $part) {
            $clean = self::normalize($part);

            if ($clean !== null) {
                return [$clean, $currency];
            }
        }

        return [null, $currency];
    }

}

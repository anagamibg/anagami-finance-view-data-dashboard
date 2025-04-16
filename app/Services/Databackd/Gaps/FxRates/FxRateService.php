<?php

namespace App\Services\Databackd\Gaps\FxRates;

use App\Models\Databackd\FinFxRate;
use App\Scopes\CompanyScope;
use App\Services\Databackd\Data\FinEntryImporter;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;

/**
 * Exchange rates by date (B6): lookup, conversion and the ECB daily reference rates.
 *
 * Storage: `fin_fx_rates` - `rate` is the number of units of `currency` for 1 unit of `base_currency` (the ECB
 * convention: 1 EUR = 1.0850 USD is stored as base EUR, currency USD, rate 1.0850). Manual rows and ECB rows live
 * in the same table (`source` manual|ecb), unique per company, date, base and currency.
 *
 * Lookup rules of rate():
 *  - the same currency is 1.0;
 *  - the rate of the nearest PREVIOUS date (on or before the requested date) is used, but never one that is older
 *    than MAX_AGE_DAYS (31 days: the ECB publishes only on TARGET working days, so gaps are at most ~5 days; a rate
 *    entered by hand once a month still covers the whole next month). Older or no rate -> null (the caller decides:
 *    keep the old behaviour, show a warning ...). A future date uses the latest rate under the same age limit;
 *  - BGN <-> EUR is the fixed legal rate (FinEntryImporter::BGN_PER_EUR = 1.95583) for any date since 1999-01-01
 *    and needs no stored row; stored BGN rows (e.g. from older ECB files) are informational only and never used;
 *  - a missing direct pair is derived from the inverse pair (stored as currency -> base) and then crossed through
 *    EUR (GBP per USD = GBP per EUR / USD per EUR), each leg with its own nearest previous date.
 *
 * Tenant: all queries use the explicit company id given to the constructor (works in console and queue code).
 * Series are cached per instance - call forget() after the table was changed through another path.
 */
class FxRateService
{

    const ECB_DAILY_URL = 'https://www.ecb.europa.eu/stats/eurofxref/eurofxref-daily.xml';

    /** ECB feed namespace of the rate cubes (checked loosely - the local name is what counts). */
    const ECB_NAMESPACE = 'http://www.ecb.int/vocabulary/2002-08-01/eurofxref';

    /** HTTP timeout of the ECB request, seconds. */
    const HTTP_TIMEOUT = 10;

    /** A rate older than this many days (against the requested date) is not used. */
    const MAX_AGE_DAYS = 31;

    /** The BGN peg is valid since the introduction of the euro (1999-01-01). */
    const PEG_SINCE = '1999-01-01';

    protected int $companyId;

    protected int $maxAgeDays = self::MAX_AGE_DAYS;

    /** @var array<string, array{dates: string[], rates: float[]}> series per "BASE>CUR" */
    protected array $series = [];

    /** @var array{date: string, saved: int, skipped: int}|null result of the last storeEcb() */
    protected ?array $lastStore = null;

    public function __construct(?int $companyId = null)
    {
        $this->companyId = $companyId ?: company()->id;
    }

    // ------------------------------------------------------------------ lookup

    /**
     * Units of $currency for 1 unit of $base on $date (nearest previous stored date, see the class description).
     * Example: rate('USD', '2026-10-27') = 1.0850 -> 1 EUR = 1.0850 USD; rate('EUR', $d, 'USD') = 0.9217.
     */
    public function rate(string $currency, \DateTimeInterface|string $date, string $base = 'EUR'): ?float
    {
        $currency = $this->code($currency);
        $base = $this->code($base);

        if ($currency === '' || $base === '') {
            return null;
        }

        if ($currency === $base) {
            return 1.0;
        }

        $day = $this->day($date);

        if ($day === null) {
            return null;
        }

        if ($base === 'EUR') {
            return $this->perEur($currency, $day);
        }

        if ($currency === 'EUR') {
            $perEur = $this->perEur($base, $day);

            return $perEur !== null ? 1 / $perEur : null;
        }

        // two non-EUR currencies: an explicitly stored pair (or its inverse) first, then the cross through EUR
        $pair = $this->pair($base, $currency, $day);

        if ($pair !== null) {
            return $pair;
        }

        $a = $this->perEur($currency, $day);
        $b = $this->perEur($base, $day);

        return ($a !== null && $b !== null && $b > 0) ? $a / $b : null;
    }

    /**
     * Converts an amount (not rounded - round the result as needed): amount x units of $to per 1 unit of $from.
     * Null when there is no usable rate.
     */
    public function convert(float $amount, string $from, string $to, \DateTimeInterface|string $date): ?float
    {
        $rate = $this->rate($to, $date, $from);

        return $rate === null ? null : $amount * $rate;
    }

    /** The age limit of rate(), days. */
    public function maxAgeDays(): int
    {
        return $this->maxAgeDays;
    }

    public function setMaxAgeDays(int $days): static
    {
        $this->maxAgeDays = max(0, $days);

        return $this;
    }

    /** Drops the cached series (after rows were added, changed or deleted). */
    public function forget(): static
    {
        $this->series = [];

        return $this;
    }

    /**
     * Stored rates of one currency for charts, oldest first.
     *
     * @return array<int, array{date: string, rate: float, source: string}>
     */
    public function history(string $currency, ?string $from = null, ?string $to = null, string $base = 'EUR', int $limit = 1000): array
    {
        $query = DB::table('fin_fx_rates')
            ->where('company_id', $this->companyId)
            ->where('base_currency', $this->code($base))
            ->where('currency', $this->code($currency));

        if ($from) {
            $query->where('rate_date', '>=', $from);
        }

        if ($to) {
            $query->where('rate_date', '<=', $to);
        }

        // newest $limit rows, returned oldest first
        $rows = $query->orderByDesc('rate_date')->limit(max(1, $limit))->get(['rate_date', 'rate', 'source'])->reverse();

        return $rows->map(fn($r) => ['date' => (string)$r->rate_date, 'rate' => (float)$r->rate, 'source' => (string)$r->source])->values()->all();
    }

    /**
     * Currencies that have at least one stored rate.
     *
     * @return string[]
     */
    public function currencies(string $base = 'EUR'): array
    {
        return DB::table('fin_fx_rates')
            ->where('company_id', $this->companyId)
            ->where('base_currency', $this->code($base))
            ->distinct()->orderBy('currency')->pluck('currency')->all();
    }

    // ------------------------------------------------------------------ ECB

    /**
     * Downloads the ECB daily reference rates (EUR base) and parses them. The ONLY place that talks to the network;
     * it is called from the "Fetch from ECB" button only (never automatically).
     *
     * @return array{date: string, rates: array<string, float>} date of the rates (YYYY-MM-DD) and units of currency per 1 EUR
     * @throws FxRateException with a translated message on a network error, a bad HTTP status or an unreadable feed
     */
    public function fetchEcb(): array
    {
        try {
            // SECURITY (S6/P5): fixed URL, no redirects (a redirect could only lead away from the ECB), 2 MB read cap via the checks below
            $response = Http::timeout(self::HTTP_TIMEOUT)
                ->connectTimeout(self::HTTP_TIMEOUT)
                ->withOptions(['allow_redirects' => false])
                ->accept('application/xml, text/xml')
                ->get(self::ECB_DAILY_URL);
        } catch (\Throwable $e) {
            // ConnectionException (DNS, refused, timeout) and anything else the HTTP client throws
            throw new FxRateException(__('databackd-gaps-fx-rates.errors.network'), 0, $e);
        }

        if (!$response->successful()) {
            throw new FxRateException(__('databackd-gaps-fx-rates.errors.http_status', ['status' => $response->status()]));
        }

        return $this->parseEcb((string)$response->body());
    }

    /**
     * Parses the ECB feed: <Cube><Cube time="2026-10-27"><Cube currency="USD" rate="1.0850"/>...
     * The XML is read with DOM without entity substitution and without network access; a document with a DOCTYPE or
     * entity declarations is refused (the feed has none), so external entities and entity expansion are impossible.
     *
     * @return array{date: string, rates: array<string, float>}
     * @throws FxRateException when the document is not a valid feed
     */
    public function parseEcb(string $xml): array
    {
        $xml = trim($xml);
        $invalid = fn() => new FxRateException(__('databackd-gaps-fx-rates.errors.invalid_feed'));

        if ($xml === '' || strlen($xml) > 2000000 || stripos($xml, '<!DOCTYPE') !== false || stripos($xml, '<!ENTITY') !== false) {
            throw $invalid();
        }

        $previous = libxml_use_internal_errors(true);
        $doc = new \DOMDocument();

        try {
            $loaded = $doc->loadXML($xml, LIBXML_NONET | LIBXML_NOBLANKS);
        } finally {
            libxml_clear_errors();
            libxml_use_internal_errors($previous);
        }

        if (!$loaded || $doc->doctype !== null) {
            throw $invalid();
        }

        $xpath = new \DOMXPath($doc);
        $days = $xpath->query('//*[local-name()="Cube"][@time]');

        if ($days === false || $days->length === 0) {
            throw $invalid();
        }

        $day = $days->item(0);
        $date = trim((string)$day->getAttribute('time'));

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            throw $invalid();
        }

        $rates = [];

        foreach ($xpath->query('./*[local-name()="Cube"][@currency][@rate]', $day) as $node) {
            $currency = $this->code($node->getAttribute('currency'));
            $rate = trim((string)$node->getAttribute('rate'));

            if (preg_match('/^[A-Z]{3}$/', $currency) && is_numeric($rate) && (float)$rate > 0 && is_finite((float)$rate)) {
                $rates[$currency] = (float)$rate;
            }
        }

        if (empty($rates)) {
            throw $invalid();
        }

        return ['date' => $date, 'rates' => $rates];
    }

    /**
     * Saves the result of fetchEcb()/parseEcb() as rows with base EUR and source ecb: new rows are created, rows of the
     * same date and currency that came from the ECB are updated. A MANUAL rate of the same date and currency stays
     * unchanged unless $overwriteManual is true. EUR itself is skipped (the base). A BGN row is stored like any other
     * but never used by rate(): the fixed peg wins.
     *
     * @param array{date: string, rates: array<string, float>} $data
     * @return int number of rows created or updated (lastStoreResult() also has the number of skipped manual rows)
     */
    public function storeEcb(array $data, bool $overwriteManual = false, ?int $userId = null): int
    {
        $date = (string)($data['date'] ?? '');

        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !checkdate((int)substr($date, 5, 2), (int)substr($date, 8, 2), (int)substr($date, 0, 4))) {
            throw new \InvalidArgumentException('Invalid ECB rate date: ' . $date);
        }

        $saved = 0;
        $skipped = 0;

        DB::transaction(function () use ($data, $date, $overwriteManual, $userId, &$saved, &$skipped) {
            $existing = FinFxRate::withoutGlobalScope(CompanyScope::class)
                ->where('company_id', $this->companyId)
                ->where('rate_date', $date)
                ->where('base_currency', 'EUR')
                ->get()->keyBy('currency');

            foreach ((array)($data['rates'] ?? []) as $currency => $rate) {
                $currency = $this->code((string)$currency);
                $rate = is_numeric($rate) ? (float)$rate : 0.0;

                if (!preg_match('/^[A-Z]{3}$/', $currency) || $currency === 'EUR' || $rate <= 0 || !is_finite($rate)) {
                    continue;
                }

                $row = $existing->get($currency);

                if ($row) {
                    if ($row->source === 'manual' && !$overwriteManual) {
                        $skipped++;
                        continue;
                    }
                } else {
                    $row = new FinFxRate();
                    $row->company_id = $this->companyId;
                    $row->rate_date = $date;
                    $row->base_currency = 'EUR';
                    $row->currency = $currency;
                    $row->created_by = $userId;
                }

                $row->rate = $rate;
                $row->source = 'ecb';
                $row->save();
                $saved++;
            }
        });

        $this->forget();
        $this->lastStore = ['date' => $date, 'saved' => $saved, 'skipped' => $skipped];

        return $saved;
    }

    /** @return array{date: string, saved: int, skipped: int}|null */
    public function lastStoreResult(): ?array
    {
        return $this->lastStore;
    }

    // ------------------------------------------------------------------ internals

    /** Units of $currency for 1 EUR on $day (BGN peg -> stored EUR->X -> inverse of stored X->EUR). */
    protected function perEur(string $currency, string $day): ?float
    {
        if ($currency === 'EUR') {
            return 1.0;
        }

        if ($currency === 'BGN') {
            return $day >= self::PEG_SINCE ? FinEntryImporter::BGN_PER_EUR : null;
        }

        $direct = $this->lookup('EUR', $currency, $day);

        if ($direct !== null) {
            return $direct;
        }

        $inverse = $this->lookup($currency, 'EUR', $day);

        return ($inverse !== null && $inverse > 0) ? 1 / $inverse : null;
    }

    /** Units of $currency for 1 $base from an explicitly stored pair or its inverse. */
    protected function pair(string $base, string $currency, string $day): ?float
    {
        $direct = $this->lookup($base, $currency, $day);

        if ($direct !== null) {
            return $direct;
        }

        $inverse = $this->lookup($currency, $base, $day);

        return ($inverse !== null && $inverse > 0) ? 1 / $inverse : null;
    }

    /** The stored rate of the nearest previous date within the age limit. */
    protected function lookup(string $base, string $currency, string $day): ?float
    {
        $series = $this->series($base, $currency);
        $dates = $series['dates'];
        $low = 0;
        $high = count($dates) - 1;
        $found = -1;

        while ($low <= $high) {
            $mid = intdiv($low + $high, 2);

            if ($dates[$mid] <= $day) {
                $found = $mid;
                $low = $mid + 1;
            } else {
                $high = $mid - 1;
            }
        }

        if ($found < 0) {
            return null;
        }

        $age = intdiv(strtotime($day . ' 00:00:00 UTC') - strtotime($dates[$found] . ' 00:00:00 UTC'), 86400);

        return $age <= $this->maxAgeDays ? $series['rates'][$found] : null;
    }

    /** @return array{dates: string[], rates: float[]} */
    protected function series(string $base, string $currency): array
    {
        $key = $base . '>' . $currency;

        if (!isset($this->series[$key])) {
            $rows = DB::table('fin_fx_rates')
                ->where('company_id', $this->companyId)
                ->where('base_currency', $base)
                ->where('currency', $currency)
                ->where('rate', '>', 0)
                ->orderBy('rate_date')
                ->get(['rate_date', 'rate']);

            $this->series[$key] = [
                'dates' => $rows->map(fn($r) => (string)$r->rate_date)->all(),
                'rates' => $rows->map(fn($r) => (float)$r->rate)->all(),
            ];
        }

        return $this->series[$key];
    }

    protected function code(string $currency): string
    {
        return strtoupper(trim($currency));
    }

    /** YYYY-MM-DD of a date object or a date string; null when it cannot be read. */
    protected function day(\DateTimeInterface|string $date): ?string
    {
        if ($date instanceof \DateTimeInterface) {
            return $date->format('Y-m-d');
        }

        $date = trim($date);

        if (preg_match('/^\d{4}-\d{2}-\d{2}/', $date)) {
            return substr($date, 0, 10);
        }

        $time = strtotime($date);

        return $time === false ? null : date('Y-m-d', $time);
    }

}

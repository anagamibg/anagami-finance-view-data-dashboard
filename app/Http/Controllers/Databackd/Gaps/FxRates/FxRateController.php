<?php

namespace App\Http\Controllers\Databackd\Gaps\FxRates;

use App\Helper\Reply;
use App\Http\Controllers\AccountBaseController;
use App\Http\Requests\Databackd\Gaps\FxRates\StoreFxRate;
use App\Http\Requests\Databackd\Gaps\FxRates\UpdateFxRate;
use App\Models\Databackd\FinFxRate;
use App\Services\Databackd\Gaps\FxRates\FxRateException;
use App\Services\Databackd\Gaps\FxRates\FxRateService;
use Carbon\Carbon;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * Exchange rates by date (B6): list with filters, manual rates (add / edit / delete), the "Fetch from ECB" button
 * and the small chart of one currency over time. The rates feed FxRateService (consolidation, import).
 */
class FxRateController extends AccountBaseController
{

    /** Rows per page of the list. */
    const PER_PAGE = 50;

    public function __construct()
    {
        parent::__construct();
        $this->middleware(function ($request, $next) {
            // set here: the user's locale is applied by the parent middleware, before this one
            $this->pageTitle = __('databackd-gaps-fx-rates.title');
            abort_403(in_array('client', user_roles()));

            return $next($request);
        });
    }

    public function index(Request $request)
    {
        $currency = strtoupper(trim((string)$request->query('currency')));
        $currency = preg_match('/^[A-Z]{3}$/', $currency) ? $currency : '';
        $source = in_array($request->query('source'), FinFxRate::SOURCES, true) ? $request->query('source') : '';
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $query = FinFxRate::query();

        if ($currency !== '') {
            $query->where('currency', $currency);
        }

        if ($source !== '') {
            $query->where('source', $source);
        }

        if ($from) {
            $query->where('rate_date', '>=', $from->toDateString());
        }

        if ($to) {
            $query->where('rate_date', '<=', $to->toDateString());
        }

        $summary = FinFxRate::query()
            ->selectRaw("COUNT(*) as total, COUNT(DISTINCT currency) as currencies, MAX(rate_date) as last_date, MAX(CASE WHEN source = 'ecb' THEN rate_date END) as last_ecb")
            ->first();

        $service = new FxRateService(company()->id);
        $currencies = $service->currencies();

        $this->rates = $query->orderByDesc('rate_date')->orderBy('currency')->paginate(self::PER_PAGE)->withQueryString();
        $this->currencies = $currencies;
        $this->filterCurrency = $currency;
        $this->filterSource = $source;
        $this->filterFrom = $from?->format(company()->date_format) ?? '';
        $this->filterTo = $to?->format(company()->date_format) ?? '';
        $this->filtered = $currency !== '' || $source !== '' || $from || $to;
        $this->totalRates = (int)($summary->total ?? 0);
        $this->currencyCount = (int)($summary->currencies ?? 0);
        $this->lastDate = $this->formatDate($summary->last_date ?? null);
        $this->lastEcbDate = $this->formatDate($summary->last_ecb ?? null);
        $this->chartCurrency = $currency !== '' ? $currency : (in_array('USD', $currencies, true) ? 'USD' : ($currencies[0] ?? ''));
        $this->ecbResult = session('databackd_fx_ecb_result');
        $this->maxAgeDays = $service->maxAgeDays();
        $this->companyCurrency = company()->currency->currency_code ?? 'EUR';

        return view('databackd.gaps.fx-rates.index', $this->data);
    }

    public function create()
    {
        $this->rate = null;

        return view('databackd.gaps.fx-rates.form', $this->data);
    }

    public function store(StoreFxRate $request)
    {
        $rate = new FinFxRate();
        $rate->company_id = company()->id;
        $rate->base_currency = 'EUR';
        $this->fill($rate, $request);
        $rate->created_by = user()->id;
        $rate->save();

        return Reply::success(__('databackd-gaps-fx-rates.messages.saved'));
    }

    public function edit($id)
    {
        $this->rate = FinFxRate::findOrFail($id);

        return view('databackd.gaps.fx-rates.form', $this->data);
    }

    public function update(UpdateFxRate $request, $id)
    {
        $rate = FinFxRate::findOrFail($id);
        $this->fill($rate, $request);
        $rate->save();

        return Reply::success(__('databackd-gaps-fx-rates.messages.saved'));
    }

    public function destroy($id)
    {
        FinFxRate::findOrFail($id)->delete();

        return Reply::success(__('databackd-gaps-fx-rates.messages.deleted'));
    }

    /**
     * The "Fetch from ECB" button: one HTTP GET of the daily feed, stored with source "ecb". Manual rates of the same
     * date and currency stay unchanged unless "overwrite_manual" is posted. A network or feed error is reported as a
     * message (no exception page) and nothing is saved.
     */
    public function fetchEcb(Request $request)
    {
        // SECURITY (S6/P5): every click is an outbound request to the ECB - limit it (a spamming user could get the server IP blocked there)
        $limitKey = 'databackd-ecb-fetch:' . company()->id;

        if (RateLimiter::tooManyAttempts($limitKey, 10)) {
            return Reply::error(__('databackd-gaps-fx-rates.errors.rate_limited'));
        }

        RateLimiter::hit($limitKey, 3600);

        $service = new FxRateService(company()->id);

        try {
            $data = $service->fetchEcb();
            $saved = $service->storeEcb($data, $request->boolean('overwrite_manual'), user()->id);
        } catch (FxRateException $e) {
            // AFV (S10): toastr shows the message as HTML - escaped and shortened
            return Reply::error(e(Str::limit($e->getMessage(), 200)));
        } catch (\Throwable $e) {
            report($e);

            return Reply::error(__('databackd-gaps-fx-rates.errors.unexpected'));
        }

        $stats = $service->lastStoreResult();
        $result = [
            'date' => $this->formatDate($data['date']),
            'count' => $saved,
            'skipped' => $stats['skipped'] ?? 0,
            'fetched_at' => now()->format(company()->date_format . ' ' . company()->time_format),
        ];

        // shown once more on the reloaded page
        session()->flash('databackd_fx_ecb_result', $result);

        return Reply::successWithData(__('databackd-gaps-fx-rates.messages.ecb_done', ['date' => $result['date'], 'count' => $saved]), ['result' => $result]);
    }

    /**
     * JSON for the chart of one currency (the stored rates, oldest first), optionally limited to a period.
     */
    public function chart(Request $request)
    {
        $service = new FxRateService(company()->id);
        $currencies = $service->currencies();
        $currency = strtoupper(trim((string)$request->query('currency')));
        $currency = in_array($currency, $currencies, true) ? $currency : ($currencies[0] ?? '');
        $from = $this->parseDate($request->query('from'));
        $to = $this->parseDate($request->query('to'));

        $rows = $currency === '' ? [] : $service->history($currency, $from?->toDateString(), $to?->toDateString());
        $format = company()->date_format;

        return Reply::dataOnly([
            'status' => 'success',
            'currency' => $currency,
            'labels' => array_map(fn($r) => Carbon::parse($r['date'])->format($format), $rows),
            'data' => array_map(fn($r) => $r['rate'], $rows),
            'sources' => array_map(fn($r) => $r['source'], $rows),
            'count' => count($rows),
        ]);
    }

    /** Date, currency and rate from the form; editing a rate (also an ECB one) makes it a manual rate. */
    protected function fill(FinFxRate $rate, Request $request): void
    {
        $rate->rate_date = companyToYmd($request->input('rate_date'));
        $rate->currency = strtoupper(trim((string)$request->input('currency')));
        $rate->rate = (float)$request->input('rate');
        $rate->source = 'manual';
    }

    protected function parseDate(mixed $value): ?Carbon
    {
        if (!is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::createFromFormat(company()->date_format, trim($value))->startOfDay();
        } catch (\Throwable $e) {
            return null;
        }
    }

    protected function formatDate(mixed $value): ?string
    {
        return $value ? Carbon::parse($value)->format(company()->date_format) : null;
    }

}

<?php

namespace App\Http\Requests\Databackd\Gaps\FxRates;

use App\Http\Requests\CoreRequest;
use Carbon\Carbon;
use Illuminate\Validation\Rule;

/**
 * A manual exchange rate: date (company date format, not in the future), 3-letter currency code (not EUR, the base,
 * and not BGN, the fixed rate), rate > 0 (units of the currency for 1 EUR). One rate per date and currency.
 */
class StoreFxRate extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    /** Currency to upper case; decimal comma and spaces in the rate; the date as Y-m-d for the uniqueness check. */
    protected function prepareForValidation()
    {
        $rate = str_replace([' ', "\u{00A0}"], '', (string)$this->input('rate'));

        if (substr_count($rate, ',') === 1 && !str_contains($rate, '.')) {
            $rate = str_replace(',', '.', $rate);
        }

        $this->merge([
            'currency' => strtoupper(trim((string)$this->input('currency'))),
            'rate' => $rate,
            'rate_date_ymd' => $this->ymd((string)$this->input('rate_date')),
        ]);
    }

    public function rules()
    {
        $ignoreId = (int)$this->route('rate');

        return [
            'rate_date' => [
                'required', 'date_format:' . company()->date_format,
                function ($attribute, $value, $fail) {
                    $date = $this->ymd((string)$value);

                    if ($date !== null && $date > now()->toDateString()) {
                        $fail(__('databackd-gaps-fx-rates.validation.date_future'));
                    }

                    if ($date !== null && $date < '1999-01-01') {
                        $fail(__('databackd-gaps-fx-rates.validation.date_too_old'));
                    }
                },
            ],
            'currency' => [
                // AFV (S12): BGN is always converted at the fixed rate 1.95583 (FxRateService ignores stored BGN rows)
                'required', 'regex:/^[A-Z]{3}$/', 'not_in:EUR', 'not_in:BGN',
                Rule::unique('fin_fx_rates', 'currency')
                    ->where('company_id', company()->id)
                    ->where('base_currency', 'EUR')
                    ->where('rate_date', $this->input('rate_date_ymd'))
                    ->ignore($ignoreId ?: null),
            ],
            // decimal(18,8): the smallest storable rate is 0.00000001
            'rate' => ['required', 'numeric', 'min:0.00000001', 'max:100000000'],
        ];
    }

    public function attributes()
    {
        return __('databackd-gaps-fx-rates.attributes');
    }

    public function messages()
    {
        return [
            'currency.regex' => __('databackd-gaps-fx-rates.validation.currency_format'),
            'currency.not_in' => $this->input('currency') === 'BGN'
                ? __('databackd-gaps-fx-rates.validation.currency_bgn')
                : __('databackd-gaps-fx-rates.validation.currency_not_allowed'),
            'currency.unique' => __('databackd-gaps-fx-rates.validation.exists'),
            'rate.min' => __('databackd-gaps-fx-rates.validation.rate_positive'),
            'rate.numeric' => __('databackd-gaps-fx-rates.validation.rate_numeric'),
        ];
    }

    protected function ymd(string $date): ?string
    {
        try {
            return Carbon::createFromFormat(company()->date_format, trim($date))->format('Y-m-d');
        } catch (\Throwable $e) {
            return null;
        }
    }

}

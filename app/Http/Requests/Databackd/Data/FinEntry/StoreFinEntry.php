<?php

namespace App\Http\Requests\Databackd\Data\FinEntry;

use App\Http\Controllers\Databackd\Data\FinEntryController;
use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinAccount;
use App\Models\Databackd\FinEntity;
use App\Models\Databackd\FinEntry;
use App\Services\Databackd\Data\InvoiceExpenseSync;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class StoreFinEntry extends CoreRequest
{

    /** Largest amount (amount_base must still fit decimal(15,2) after the conversion). */
    const MAX_AMOUNT = 999999999999.99;

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    /**
     * AFV (S12/A07): the amount field is a text field - "1 234,56", "1.234,56" and "1234.56" are all accepted,
     * the same as in the bulk grid and the Excel import. An unreadable value is left as typed, so "numeric" rejects it.
     */
    protected function prepareForValidation()
    {
        $data = ['currency' => strtoupper(trim((string)$this->input('currency')))];

        if ($this->has('amount') && is_scalar($this->input('amount'))) {
            $amount = self::normalizeAmount((string)$this->input('amount'));

            if ($amount !== null) {
                $data['amount'] = $amount;
            }
        }

        $this->merge($data);
    }

    /**
     * "1234.56", "1234,56", "1 234,56", "1.234,56", "1,234.56" (and a currency sign) -> "1234.56"; null when not a number.
     */
    public static function normalizeAmount(string $value): ?string
    {
        $s = preg_replace('/[\s\x{00A0}\x{202F}]|€|EUR|eur|лв\.?/u', '', trim($value));

        if ($s === '' || !preg_match('/^[+-]?[\d.,]+$/', $s)) {
            return null;
        }

        $sign = str_starts_with($s, '-') ? '-' : '';
        $s = ltrim($s, '+-');
        $comma = strrpos($s, ',');
        $dot = strrpos($s, '.');

        if ($comma !== false && $dot !== false) {
            $decimal = $comma > $dot ? ',' : '.';
            $s = str_replace($decimal === ',' ? '.' : ',', '', $s);
            $s = str_replace($decimal, '.', $s);
        }
        elseif ($comma !== false) {
            $s = substr_count($s, ',') > 1 ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        }
        elseif ($dot !== false && substr_count($s, '.') > 1) {
            $s = str_replace('.', '', $s);
        }

        return is_numeric($s) ? $sign . $s : null;
    }

    public function rules()
    {
        $companyId = company()->id;

        $dimension = fn(string $type) => [
            'nullable',
            Rule::exists('fin_dimensions', 'id')->where('company_id', $companyId)->where('type', $type),
        ];

        return [
            'entry_date' => ['required', 'date_format:' . company()->date_format],
            'entity_id' => ['required', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
            'account_id' => ['required', Rule::exists('fin_accounts', 'id')->where('company_id', $companyId)],
            'kind' => ['required', Rule::in(FinEntry::KINDS)],
            'amount' => ['required', 'numeric', 'min:0.01', 'max:' . self::MAX_AMOUNT],
            'currency' => ['required', 'string', 'alpha:ascii', 'size:3'], // S6/P3: a currency code, not any 3 characters (it is printed in the entries table)
            'region_id' => $dimension('region'),
            'client_segment_id' => $dimension('client_segment'),
            'product_group_id' => $dimension('product_group'),
            'cost_center_id' => $dimension('cost_center'),
            'counterparty_name' => ['nullable', 'string', 'max:191'],
            'document_no' => ['nullable', 'string', 'max:60'],
            'description' => ['nullable', 'string', 'max:191'],
        ];
    }

    /**
     * AFV (S12/A07): rules that need the database
     *  - revenue / expense accounts take turnovers only: a "balance" on them is ignored by every P&L report;
     *  - an inactive account / legal entity is accepted only when it is already the value of the edited entry;
     *  - a foreign currency needs a known rate on the entry date (fin_fx_rates, the BGN/EUR peg or the company rate),
     *    otherwise the amount would silently be taken 1:1 into the base currency.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $errors = $validator->errors();
            $entry = $this->route('id') ? FinEntry::find((int)$this->route('id')) : null;
            $fromDocument = $entry && in_array($entry->source, InvoiceExpenseSync::SOURCES, true);

            $account = $errors->has('account_id') ? null : FinAccount::find((int)$this->input('account_id'));

            if ($account && !$account->is_active && (!$entry || $entry->account_id !== $account->id)) {
                $errors->add('account_id', __('databackd-data-entries.messages.account_inactive'));
            }

            if ($account && !$errors->has('kind') && $this->input('kind') === 'balance' && in_array($account->type, ['revenue', 'expense'], true)) {
                $errors->add('kind', __('databackd-data-entries.messages.kind_pnl_flow'));
            }

            $entity = $errors->has('entity_id') ? null : FinEntity::find((int)$this->input('entity_id'));

            if ($entity && !$entity->is_active && (!$entry || $entry->entity_id !== $entity->id)) {
                $errors->add('entity_id', __('databackd-data-entries.messages.entity_inactive'));
            }

            if ($fromDocument || $errors->hasAny(['currency', 'entry_date', 'amount'])) {
                return;
            }

            $currency = (string)$this->input('currency');
            $date = companyToYmd($this->input('entry_date'));

            if (!FinEntryController::hasRate($currency, $date)) {
                $errors->add('currency', __('databackd-data-entries.messages.no_rate', [
                    'currency' => $currency,
                    'base' => FinEntryController::baseCurrency(),
                    'date' => $this->input('entry_date'),
                ]));

                return;
            }

            if (abs(FinEntryController::convertToBase((float)$this->input('amount'), $currency, $date)) > self::MAX_AMOUNT) {
                $errors->add('amount', __('databackd-data-entries.messages.amount_too_large'));
            }
        });
    }

    public function attributes()
    {
        return __('databackd-data-entries.attributes');
    }

}

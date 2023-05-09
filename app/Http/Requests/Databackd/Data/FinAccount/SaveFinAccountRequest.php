<?php

namespace App\Http\Requests\Databackd\Data\FinAccount;

use App\Http\Controllers\Databackd\Data\FinAccountController;
use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinAccount;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Validation for creating and updating a chart-of-accounts item.
 * On update the route parameter {account} holds the id of the edited account.
 */
class SaveFinAccountRequest extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    public function rules()
    {
        $companyId = company()->id;
        $accountId = $this->route('account');

        return [
            'code' => [
                'required',
                'string',
                'max:20',
                'regex:/^[A-Za-z0-9._-]+$/',
                Rule::unique('fin_accounts', 'code')
                    ->where('company_id', $companyId)
                    ->ignore($accountId),
            ],
            'name' => 'required|string|max:191',
            'type' => ['required', Rule::in(FinAccount::TYPES)],
            'subtype' => ['nullable', Rule::in(FinAccountController::SUBTYPES)],
            'parent_id' => [
                'nullable',
                'integer',
                Rule::exists('fin_accounts', 'id')->where('company_id', $companyId),
            ],
            'is_active' => 'nullable',
        ];
    }

    /**
     * S12/A07: the subtype must belong to the type (the reports read it only within the type), and the code of a
     * standard NSS account cannot change (the chart would re-create the old code, the sync and the statutory reports
     * look the accounts up by it).
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $errors = $validator->errors();

            if (!$errors->has('type') && !$errors->has('subtype') && $this->filled('subtype')
                && !in_array($this->input('subtype'), FinAccountController::SUBTYPES_BY_TYPE[$this->input('type')] ?? [], true)) {
                $errors->add('subtype', __('databackd-data-accounts.validation.subtype_type'));
            }

            $account = $this->route('account') ? FinAccount::find((int)$this->route('account')) : null;

            if ($account && !$errors->has('code') && trim((string)$this->input('code')) !== $account->code
                && in_array($account->code, FinAccountController::standardCodes(), true)) {
                $errors->add('code', __('databackd-data-accounts.validation.standard_code'));
            }
        });
    }

    public function messages()
    {
        return [
            'code.unique' => __('databackd-data-accounts.validation.code_taken'),
        ];
    }

    public function attributes()
    {
        return [
            'code' => __('databackd-data-accounts.attributes.code'),
            'name' => __('databackd-data-accounts.attributes.name'),
            'type' => __('databackd-data-accounts.attributes.type'),
            'subtype' => __('databackd-data-accounts.attributes.subtype'),
            'parent_id' => __('databackd-data-accounts.attributes.parent_id'),
            'is_active' => __('databackd-data-accounts.attributes.is_active'),
        ];
    }

}

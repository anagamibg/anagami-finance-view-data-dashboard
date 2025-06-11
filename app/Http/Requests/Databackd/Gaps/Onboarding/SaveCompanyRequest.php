<?php

namespace App\Http\Requests\Databackd\Gaps\Onboarding;

use App\Http\Requests\CoreRequest;

/**
 * Step 1 of the setup wizard: company name, EIK / VAT number and address.
 */
class SaveCompanyRequest extends CoreRequest
{

    public function authorize()
    {
        return in_array('admin', user_roles());
    }

    protected function prepareForValidation()
    {
        $this->merge([
            'company_name' => trim((string)$this->input('company_name')),
            'tax_number' => trim((string)$this->input('tax_number')),
            'address' => trim((string)$this->input('address')),
        ]);
    }

    public function rules()
    {
        return [
            'company_name' => ['required', 'string', 'max:191'],
            'tax_number' => ['nullable', 'string', 'max:50'],
            'address' => ['required', 'string', 'max:500'],
        ];
    }

    public function attributes()
    {
        return __('databackd-gaps-onboarding.attributes');
    }

}

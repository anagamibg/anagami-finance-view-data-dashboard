<?php

namespace App\Http\Requests\Databackd\Gaps\Onboarding;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinEntity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

/**
 * Step 2 of the setup wizard: the main legal entity (only while the company has no entities yet) and up to five
 * subsidiaries. Subsidiary fields are parallel arrays (sub_name[], sub_eik[], sub_country[], sub_currency[], sub_pct[]);
 * rows without a name are ignored.
 */
class SaveEntitiesRequest extends CoreRequest
{

    const MAX_SUBSIDIARIES = 5;

    public function authorize()
    {
        return in_array('admin', user_roles());
    }

    protected function prepareForValidation()
    {
        $upper = fn($value) => is_string($value) ? strtoupper(trim($value)) : $value;
        $trim = fn($value) => is_string($value) ? trim($value) : $value;

        $this->merge([
            'main_name' => $trim($this->input('main_name')),
            'main_country' => $upper($this->input('main_country')),
            'main_currency' => $upper($this->input('main_currency')),
            'sub_name' => array_map($trim, (array)$this->input('sub_name', [])),
            'sub_country' => array_map($upper, (array)$this->input('sub_country', [])),
            'sub_currency' => array_map($upper, (array)$this->input('sub_currency', [])),
        ]);
    }

    public function rules()
    {
        $companyId = company()->id;
        $hasEntities = FinEntity::where('company_id', $companyId)->exists();
        $unique = fn() => Rule::unique('fin_entities', 'name')->where('company_id', $companyId);

        return [
            'main_name' => [Rule::requiredIf(!$hasEntities), 'nullable', 'string', 'max:191', $unique()],
            'main_eik' => ['nullable', 'string', 'max:20'],
            'main_country' => ['nullable', 'alpha', 'size:2'],
            'main_currency' => ['nullable', 'alpha', 'size:3'],
            'sub_name' => ['nullable', 'array', 'max:' . self::MAX_SUBSIDIARIES],
            'sub_name.*' => ['nullable', 'string', 'max:191', $unique(), 'distinct:ignore_case'],
            'sub_eik.*' => ['nullable', 'string', 'max:20'],
            'sub_country.*' => ['nullable', 'alpha', 'size:2'],
            'sub_currency.*' => ['nullable', 'alpha', 'size:3'],
            'sub_pct.*' => ['nullable', 'numeric', 'between:0,100'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $main = mb_strtolower((string)$this->input('main_name'));

            if ($main === '') {
                return;
            }

            foreach ((array)$this->input('sub_name', []) as $index => $name) {
                if (mb_strtolower((string)$name) === $main) {
                    $validator->errors()->add('sub_name.' . $index, __('databackd-gaps-onboarding.validation.name_same_as_main'));
                }
            }
        });
    }

    public function attributes()
    {
        $attributes = __('databackd-gaps-onboarding.attributes');

        return is_array($attributes) ? $attributes : [];
    }

    public function messages()
    {
        return [
            'main_name.required' => __('databackd-gaps-onboarding.validation.main_required'),
            'main_name.unique' => __('databackd-gaps-onboarding.validation.name_taken'),
            'sub_name.*.unique' => __('databackd-gaps-onboarding.validation.name_taken'),
            'sub_name.*.distinct' => __('databackd-gaps-onboarding.validation.name_duplicate'),
        ];
    }

}

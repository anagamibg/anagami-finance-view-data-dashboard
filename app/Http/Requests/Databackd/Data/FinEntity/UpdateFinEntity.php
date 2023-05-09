<?php

namespace App\Http\Requests\Databackd\Data\FinEntity;

use App\Http\Controllers\Databackd\Data\FinEntryController;
use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinEntity;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Validation\Rule;

class UpdateFinEntity extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    protected function prepareForValidation()
    {
        $this->merge([
            // S12/A07: trimmed before the unique check (fill() stores the trimmed name; " ACME" must collide with "ACME")
            'name' => is_string($this->input('name')) ? trim(preg_replace('/\s+/u', ' ', $this->input('name'))) : $this->input('name'),
            'country' => strtoupper(trim((string)$this->input('country'))),
            'currency' => strtoupper(trim((string)$this->input('currency'))),
        ]);
    }

    public function rules()
    {
        $id = (int)$this->route('entity');

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('fin_entities', 'name')->where('company_id', company()->id)->ignore($id),
            ],
            'eik' => ['nullable', 'string', 'max:20'],
            'country' => ['required', 'alpha', 'size:2'],
            // S12/A07: a currency with a known rate (or the base / BGN-EUR pair) - entries of the entity are converted with it
            'currency' => ['required', 'alpha', 'size:3', Rule::in(FinEntryController::currencyCodes(FinEntity::whereKey((int)$this->route('entity'))->value('currency'))->all())],
            'parent_id' => [
                'nullable', 'integer',
                Rule::exists('fin_entities', 'id')->where('company_id', company()->id),
            ],
            'ownership_pct' => ['nullable', 'numeric', 'between:0,100'],
            'sort' => ['nullable', 'integer', 'min:0', 'max:65535'],
        ];
    }

    /**
     * Forbid cycles: an entity cannot be its own parent nor the parent of one of its ancestors.
     */
    public function withValidator(Validator $validator)
    {
        $validator->after(function (Validator $validator) {
            $parentId = (int)$this->input('parent_id');
            $id = (int)$this->route('entity');

            if (!$parentId || $validator->errors()->has('parent_id')) {
                return;
            }

            if ($parentId === $id) {
                $validator->errors()->add('parent_id', __('databackd-data-dimensions.validation_parent_self'));
                return;
            }

            // Walk up from the proposed parent; meeting the edited entity means a cycle
            $parents = FinEntity::pluck('parent_id', 'id');
            $seen = [];
            $current = $parentId;

            while ($current && !isset($seen[$current])) {
                if ($current === $id) {
                    $validator->errors()->add('parent_id', __('databackd-data-dimensions.validation_parent_cycle'));
                    return;
                }

                $seen[$current] = true;
                $current = (int)($parents[$current] ?? 0);
            }
        });
    }

    public function attributes()
    {
        return __('databackd-data-dimensions.attributes');
    }

    public function messages()
    {
        return [
            // S12/A07 (from A06): names are unique per company - the consolidation and the imports find legal entities by name
            'name.unique' => __('databackd-data-dimensions.validation_entity_name_taken'),
            'currency.in' => __('databackd-data-dimensions.validation_currency_rate'),
        ];
    }

}

<?php

namespace App\Http\Requests\Databackd\Data\FinDimension;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinDimension;
use Illuminate\Validation\Rule;

class StoreFinDimension extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    public function rules()
    {
        return [
            'type' => ['required', Rule::in(FinDimension::TYPES)],
            'name' => [
                'required', 'string', 'max:191',
                // Name is unique within company + dimension type
                Rule::unique('fin_dimensions', 'name')
                    ->where('company_id', company()->id)
                    ->where('type', $this->input('type')),
            ],
            'code' => ['nullable', 'string', 'max:30'],
        ];
    }

    public function attributes()
    {
        return __('databackd-data-dimensions.attributes');
    }

    public function messages()
    {
        return ['name.unique' => __('databackd-data-dimensions.validation_name_taken')];
    }

}

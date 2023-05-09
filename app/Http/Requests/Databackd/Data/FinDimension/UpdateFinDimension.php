<?php

namespace App\Http\Requests\Databackd\Data\FinDimension;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinDimension;
use Illuminate\Validation\Rule;

class UpdateFinDimension extends CoreRequest
{

    public function authorize()
    {
        return !in_array('client', user_roles());
    }

    public function rules()
    {
        $id = (int)$this->route('dimension');
        $dimension = FinDimension::find($id);

        return [
            'name' => [
                'required', 'string', 'max:191',
                Rule::unique('fin_dimensions', 'name')
                    ->where('company_id', company()->id)
                    ->where('type', $dimension?->type)
                    ->ignore($id),
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

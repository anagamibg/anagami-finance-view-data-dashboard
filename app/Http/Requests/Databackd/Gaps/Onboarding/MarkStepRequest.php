<?php

namespace App\Http\Requests\Databackd\Gaps\Onboarding;

use App\Http\Requests\CoreRequest;
use Illuminate\Validation\Rule;

/**
 * Mark a wizard step as done or skipped (the last step is finished with "complete").
 */
class MarkStepRequest extends CoreRequest
{

    public function authorize()
    {
        return in_array('admin', user_roles());
    }

    public function rules()
    {
        return [
            'status' => ['required', Rule::in(['done', 'skipped'])],
        ];
    }

}

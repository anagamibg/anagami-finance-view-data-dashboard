<?php

namespace App\Http\Requests\Databackd\Gaps\Onboarding;

use App\Http\Requests\CoreRequest;
use App\Services\Databackd\Gaps\Onboarding\OnboardingService;
use Illuminate\Validation\Rule;

/**
 * Step 3 of the setup wizard: the chart-of-accounts template (nss|micro).
 */
class ApplyChartRequest extends CoreRequest
{

    public function authorize()
    {
        return in_array('admin', user_roles());
    }

    public function rules()
    {
        return [
            'template' => ['required', Rule::in(OnboardingService::TEMPLATES)],
        ];
    }

    public function attributes()
    {
        return __('databackd-gaps-onboarding.attributes');
    }

    public function messages()
    {
        return ['template.required' => __('databackd-gaps-onboarding.validation.template_required')];
    }

}

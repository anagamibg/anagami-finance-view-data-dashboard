<?php

namespace App\Http\Requests\Databackd\Gaps\Alerts;

use App\Http\Requests\CoreRequest;
use App\Models\Databackd\FinAlertRule;
use Illuminate\Validation\Rule;

/**
 * Validation of an alert rule (create). The fields in `params` depend on the type:
 *  ratio_range {ratio_id, min, max}, budget_deviation {budget_id, pct, metric}, cash_below / receivables_above {amount}.
 */
class StoreAlertRuleRequest extends CoreRequest
{

    public function authorize()
    {
        return in_array('admin', user_roles());
    }

    public function rules()
    {
        $companyId = company()->id;
        $money = 'numeric|between:-999999999999,999999999999';

        $rules = [
            'name' => 'required|string|max:191',
            'type' => ['required', Rule::in($this->allowedTypes())],
            'entity_id' => ['nullable', 'integer', Rule::exists('fin_entities', 'id')->where('company_id', $companyId)],
            'severity' => ['required', Rule::in(FinAlertRule::SEVERITIES)],
            'recipients' => 'nullable|string|max:1000',
        ];

        switch ($this->input('type')) {
            case 'ratio_range':
                $rules['params.ratio_id'] = ['required', 'integer', Rule::exists('fin_ratios', 'id')->where('company_id', $companyId)];
                $rules['params.min'] = 'nullable|numeric|between:-9999999,9999999';
                $rules['params.max'] = 'nullable|numeric|between:-9999999,9999999';
                break;
            case 'budget_deviation':
                $rules['params.budget_id'] = ['required', 'integer', Rule::exists('fin_budgets', 'id')->where('company_id', $companyId)];
                $rules['params.pct'] = 'required|numeric|between:0.01,10000';
                $rules['params.metric'] = ['nullable', Rule::in(['any', 'revenue', 'expense', 'profit'])];
                break;
            case 'cash_below':
            case 'receivables_above':
                $rules['params.amount'] = 'required|' . $money;
                break;
        }

        return $rules;
    }

    public function withValidator($validator)
    {
        $validator->after(function ($validator) {
            $errors = $validator->errors();

            if ($this->input('type') === 'ratio_range' && !$errors->has('params.min') && !$errors->has('params.max')) {
                $min = $this->input('params.min');
                $max = $this->input('params.max');

                if (($min === null || $min === '') && ($max === null || $max === '')) {
                    $validator->errors()->add('params.min', __('databackd-gaps-alerts.validation.range_required'));
                } elseif ($min !== null && $min !== '' && $max !== null && $max !== '' && (float)$min > (float)$max) {
                    $validator->errors()->add('params.max', __('databackd-gaps-alerts.validation.range_order'));
                }
            }

            if (!$errors->has('recipients')) {
                $invalid = self::splitRecipients((string)$this->input('recipients', ''))['invalid'];

                if ($invalid) {
                    $validator->errors()->add('recipients', __('databackd-gaps-alerts.validation.recipients_invalid', ['email' => e($invalid[0])])); // shown as HTML (help-block)
                }
            }
        });
    }

    /**
     * The validated input as attributes of FinAlertRule (params normalized to the shape the checker expects).
     */
    public function ruleAttributes(): array
    {
        $input = $this->validated();
        $type = $input['type'];
        $p = (array)($input['params'] ?? []);

        $params = match ($type) {
            'ratio_range' => [
                'ratio_id' => (int)$p['ratio_id'],
                'min' => isset($p['min']) && $p['min'] !== '' ? (float)$p['min'] : null,
                'max' => isset($p['max']) && $p['max'] !== '' ? (float)$p['max'] : null,
            ],
            'budget_deviation' => ['budget_id' => (int)$p['budget_id'], 'pct' => (float)$p['pct'], 'metric' => $p['metric'] ?? 'any'],
            default => ['amount' => (float)$p['amount']],
        };

        return [
            'name' => trim($input['name']),
            'type' => $type,
            // the budget defines the scope of a budget_deviation rule
            'entity_id' => $type === 'budget_deviation' || empty($input['entity_id']) ? null : (int)$input['entity_id'],
            'params' => $params,
            'severity' => $input['severity'],
            'notify_app' => $this->boolean('notify_app'),
            'notify_email' => $this->boolean('notify_email'),
            'recipients' => self::splitRecipients((string)($input['recipients'] ?? ''))['valid'],
            'is_active' => $this->boolean('is_active'),
        ];
    }

    /**
     * Splits a comma / semicolon / white space / new line separated list of e-mails.
     *
     * @return array{valid: string[], invalid: string[]}
     */
    public static function splitRecipients(string $value): array
    {
        $valid = [];
        $invalid = [];

        foreach (preg_split('/[\s,;]+/', $value) ?: [] as $item) {
            $item = trim($item);

            if ($item === '') {
                continue;
            }

            if (filter_var($item, FILTER_VALIDATE_EMAIL)) {
                $valid[strtolower($item)] = strtolower($item);
            } else {
                $invalid[] = $item;
            }
        }

        return ['valid' => array_values($valid), 'invalid' => $invalid];
    }

    /**
     * Rule types that can be chosen: the types of a module that is not in the package are left out.
     *
     * @return string[]
     */
    protected function allowedTypes(): array
    {
        return self::availableTypes($this->currentType());
    }

    /**
     * @param string|null $keep type of the rule that is being edited - stays allowed even when its module is gone
     * @return string[]
     */
    public static function availableTypes(?string $keep = null): array
    {
        return array_values(array_filter(FinAlertRule::TYPES, function ($type) use ($keep) {
            return $type !== 'budget_deviation' || $keep === 'budget_deviation' || databackd_module('databackd_planning');
        }));
    }

    /** Type of the edited rule (null when creating). */
    protected function currentType(): ?string
    {
        return null;
    }

    public function messages()
    {
        return [
            'name.required' => __('databackd-gaps-alerts.validation.name_required'),
            'name.max' => __('databackd-gaps-alerts.validation.name_max'),
            'type.required' => __('databackd-gaps-alerts.validation.type_invalid'),
            'type.in' => __('databackd-gaps-alerts.validation.type_invalid'),
            'entity_id.exists' => __('databackd-gaps-alerts.validation.entity_invalid'),
            'severity.in' => __('databackd-gaps-alerts.validation.severity_invalid'),
            'recipients.max' => __('databackd-gaps-alerts.validation.recipients_max'),
            'params.ratio_id.required' => __('databackd-gaps-alerts.validation.ratio_required'),
            'params.ratio_id.exists' => __('databackd-gaps-alerts.validation.ratio_required'),
            'params.min.numeric' => __('databackd-gaps-alerts.validation.numeric'),
            'params.min.between' => __('databackd-gaps-alerts.validation.numeric'),
            'params.max.numeric' => __('databackd-gaps-alerts.validation.numeric'),
            'params.max.between' => __('databackd-gaps-alerts.validation.numeric'),
            'params.budget_id.required' => __('databackd-gaps-alerts.validation.budget_required'),
            'params.budget_id.exists' => __('databackd-gaps-alerts.validation.budget_required'),
            'params.pct.required' => __('databackd-gaps-alerts.validation.pct_required'),
            'params.pct.numeric' => __('databackd-gaps-alerts.validation.pct_required'),
            'params.pct.between' => __('databackd-gaps-alerts.validation.pct_range'),
            'params.metric.in' => __('databackd-gaps-alerts.validation.metric_invalid'),
            'params.amount.required' => __('databackd-gaps-alerts.validation.amount_required'),
            'params.amount.numeric' => __('databackd-gaps-alerts.validation.amount_required'),
            'params.amount.between' => __('databackd-gaps-alerts.validation.amount_range'),
        ];
    }

    public function attributes()
    {
        return [
            'name' => __('databackd-gaps-alerts.form.name'),
            'type' => __('databackd-gaps-alerts.form.type'),
            'entity_id' => __('databackd-gaps-alerts.form.entity'),
            'severity' => __('databackd-gaps-alerts.form.severity'),
            'recipients' => __('databackd-gaps-alerts.form.recipients'),
        ];
    }

}

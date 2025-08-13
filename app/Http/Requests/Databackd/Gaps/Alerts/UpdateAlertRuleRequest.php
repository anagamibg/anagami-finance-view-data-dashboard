<?php

namespace App\Http\Requests\Databackd\Gaps\Alerts;

use App\Models\Databackd\FinAlertRule;

/**
 * Validation of an alert rule (update): the same rules as for creating one; a rule whose type belongs to a module
 * that is no longer in the package keeps its type.
 */
class UpdateAlertRuleRequest extends StoreAlertRuleRequest
{

    protected function currentType(): ?string
    {
        $id = (int)$this->route('rule');

        return $id ? FinAlertRule::where('id', $id)->value('type') : null;
    }

}

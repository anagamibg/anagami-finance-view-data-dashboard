<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One fired alert (history of fin_alert_rules).
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $rule_id
 * @property string $severity
 * @property string $message
 * @property float|null $value
 * @property float|null $threshold
 * @property \Illuminate\Support\Carbon|null $period_from
 * @property \Illuminate\Support\Carbon|null $period_to
 * @property array|null $context
 * @property bool $notified_app
 * @property bool $notified_email
 * @property \Illuminate\Support\Carbon|null $acknowledged_at
 * @property int|null $acknowledged_by
 */
class FinAlertEvent extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_alert_events';
    protected $guarded = ['id'];
    protected $casts = [
        'value' => 'float',
        'threshold' => 'float',
        'period_from' => 'date:Y-m-d',
        'period_to' => 'date:Y-m-d',
        'context' => 'array',
        'notified_app' => 'boolean',
        'notified_email' => 'boolean',
        'acknowledged_at' => 'datetime',
    ];

    public function rule(): BelongsTo
    {
        return $this->belongsTo(FinAlertRule::class, 'rule_id');
    }

}

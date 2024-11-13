<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Threshold rule checked by `php artisan databackd:check-alerts`.
 *
 * type / params:
 *  - ratio_range:        {"ratio_id": int, "min": float|null, "max": float|null}  (outside [min, max] fires)
 *  - budget_deviation:   {"budget_id": int, "pct": float}                          (|actual - budget| / budget above pct fires)
 *  - cash_below:         {"amount": float}                                         (cash 501 + 503 below amount fires)
 *  - receivables_above:  {"amount": float}                                         (receivables above amount fires)
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $type
 * @property int|null $entity_id
 * @property array|null $params
 * @property string $severity info|warning|critical
 * @property bool $notify_app
 * @property bool $notify_email
 * @property array|null $recipients
 * @property bool $is_active
 * @property string $last_state ok|triggered
 * @property \Illuminate\Support\Carbon|null $last_checked_at
 * @property int|null $created_by
 */
class FinAlertRule extends BaseModel
{

    use HasCompany;

    const TYPES = ['ratio_range', 'budget_deviation', 'cash_below', 'receivables_above'];
    const SEVERITIES = ['info', 'warning', 'critical'];

    protected $table = 'fin_alert_rules';
    protected $guarded = ['id'];
    protected $casts = [
        'params' => 'array',
        'recipients' => 'array',
        'notify_app' => 'boolean',
        'notify_email' => 'boolean',
        'is_active' => 'boolean',
        'last_checked_at' => 'datetime',
    ];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

    public function events(): HasMany
    {
        return $this->hasMany(FinAlertEvent::class, 'rule_id');
    }

}

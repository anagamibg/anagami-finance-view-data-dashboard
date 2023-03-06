<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Legal entity / subsidiary inside a company (used for consolidation).
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string|null $eik
 * @property string $country
 * @property string $currency
 * @property int|null $parent_id
 * @property string $ownership_pct
 * @property bool $is_active
 * @property int $sort
 */
class FinEntity extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_entities';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean', 'ownership_pct' => 'float'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function children(): HasMany
    {
        return $this->hasMany(self::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinEntry::class, 'entity_id');
    }

}

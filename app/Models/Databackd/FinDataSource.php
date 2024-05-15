<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Saved import source (Google Sheet / CSV URL) with its column mapping.
 *
 * @property int $id
 * @property int $company_id
 * @property string $type google_sheets|csv_url|excel
 * @property string $name
 * @property string|null $url
 * @property string|null $sheet
 * @property array|null $mapping
 * @property int|null $default_entity_id
 * @property \Illuminate\Support\Carbon|null $last_synced_at
 * @property int|null $last_batch_id
 * @property int|null $created_by
 */
class FinDataSource extends BaseModel
{

    use HasCompany;

    const TYPES = ['google_sheets', 'csv_url', 'excel'];

    protected $table = 'fin_data_sources';
    protected $guarded = ['id'];
    protected $casts = ['mapping' => 'array', 'last_synced_at' => 'datetime'];

    public function defaultEntity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'default_entity_id');
    }

    public function lastBatch(): BelongsTo
    {
        return $this->belongsTo(FinImportBatch::class, 'last_batch_id');
    }

}

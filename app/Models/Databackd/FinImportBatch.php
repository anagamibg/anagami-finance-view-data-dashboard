<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One import run of financial data (Excel, Google Sheets, CSV, API).
 *
 * @property int $id
 * @property int $company_id
 * @property string $source
 * @property string|null $file_name
 * @property string|null $source_url
 * @property int $rows_total
 * @property int $rows_imported
 * @property int $rows_failed
 * @property string $status
 * @property array|null $mapping
 * @property array|null $errors
 * @property int|null $created_by
 */
class FinImportBatch extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_import_batches';
    protected $guarded = ['id'];
    protected $casts = ['mapping' => 'array', 'errors' => 'array'];

    public function entries(): HasMany
    {
        return $this->hasMany(FinEntry::class, 'import_batch_id');
    }

}

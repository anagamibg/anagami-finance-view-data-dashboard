<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Models\User;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Universal financial record. `amount` is always positive; the sign is derived from the account type.
 * kind = flow    -> turnover for the period / a transaction on entry_date
 * kind = balance -> balance of the account at entry_date
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $entity_id
 * @property int $account_id
 * @property \Illuminate\Support\Carbon $entry_date
 * @property string $kind flow|balance
 * @property string $amount
 * @property string $currency
 * @property string $amount_base amount converted to the company base currency
 * @property int|null $region_id
 * @property int|null $client_segment_id
 * @property int|null $product_group_id
 * @property int|null $cost_center_id
 * @property int|null $client_id
 * @property string|null $counterparty_name
 * @property string|null $document_no
 * @property string|null $description
 * @property string $source manual|excel|google_sheets|csv|api|invoice|expense|demo
 * @property int|null $import_batch_id
 * @property int|null $created_by
 */
class FinEntry extends BaseModel
{

    use HasCompany;

    const KINDS = ['flow', 'balance'];
    const SOURCES = ['manual', 'excel', 'google_sheets', 'csv', 'api', 'invoice', 'expense', 'demo', 'bank_statement'];

    protected $table = 'fin_entries';
    protected $guarded = ['id'];
    protected $casts = ['entry_date' => 'date:Y-m-d', 'amount' => 'float', 'amount_base' => 'float'];

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

    public function region(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'region_id');
    }

    public function clientSegment(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'client_segment_id');
    }

    public function productGroup(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'product_group_id');
    }

    public function costCenter(): BelongsTo
    {
        return $this->belongsTo(FinDimension::class, 'cost_center_id');
    }

    public function client(): BelongsTo
    {
        return $this->belongsTo(User::class, 'client_id');
    }

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(FinImportBatch::class, 'import_batch_id');
    }

}

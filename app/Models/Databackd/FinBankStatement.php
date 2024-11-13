<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Header of an imported bank statement (MT940 or CAMT.053). The transactions are fin_entries with
 * source = 'bank_statement' and the same import_batch_id.
 *
 * @property int $id
 * @property int $company_id
 * @property int|null $import_batch_id
 * @property int|null $entity_id
 * @property string $format mt940|camt053
 * @property string|null $account_iban
 * @property string $currency
 * @property string|null $statement_no
 * @property \Illuminate\Support\Carbon|null $date_from
 * @property \Illuminate\Support\Carbon|null $date_to
 * @property float|null $opening_balance
 * @property float|null $closing_balance
 * @property int $transactions_count
 * @property string|null $file_name
 * @property int|null $created_by
 */
class FinBankStatement extends BaseModel
{

    use HasCompany;

    const FORMATS = ['mt940', 'camt053'];

    protected $table = 'fin_bank_statements';
    protected $guarded = ['id'];
    protected $casts = [
        'date_from' => 'date:Y-m-d',
        'date_to' => 'date:Y-m-d',
        'opening_balance' => 'float',
        'closing_balance' => 'float',
    ];

    public function importBatch(): BelongsTo
    {
        return $this->belongsTo(FinImportBatch::class, 'import_batch_id');
    }

    public function entity(): BelongsTo
    {
        return $this->belongsTo(FinEntity::class, 'entity_id');
    }

}

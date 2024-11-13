<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Rule that books a bank-statement transaction of a counterparty to an account.
 *
 * @property int $id
 * @property int $company_id
 * @property string $pattern name / IBAN / text fragment, case-insensitive
 * @property string $match_type contains|exact
 * @property string $direction credit|debit|any
 * @property int $account_id
 * @property int|null $entity_id
 * @property string|null $counterparty_name
 * @property int|null $created_by
 */
class FinCounterpartyMapping extends BaseModel
{

    use HasCompany;

    const MATCH_TYPES = ['contains', 'exact'];
    const DIRECTIONS = ['credit', 'debit', 'any'];

    protected $table = 'fin_counterparty_mappings';
    protected $guarded = ['id'];

    public function account(): BelongsTo
    {
        return $this->belongsTo(FinAccount::class, 'account_id');
    }

}

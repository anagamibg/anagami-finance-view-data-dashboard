<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;

/**
 * Exchange rate by date: `rate` units of `currency` for 1 unit of `base_currency` (the ECB convention: 1 EUR = 1.0850 USD).
 *
 * @property int $id
 * @property int $company_id
 * @property \Illuminate\Support\Carbon $rate_date
 * @property string $base_currency
 * @property string $currency
 * @property float $rate
 * @property string $source manual|ecb
 * @property int|null $created_by
 */
class FinFxRate extends BaseModel
{

    use HasCompany;

    const SOURCES = ['manual', 'ecb'];

    protected $table = 'fin_fx_rates';
    protected $guarded = ['id'];
    protected $casts = ['rate_date' => 'date:Y-m-d', 'rate' => 'float'];

}

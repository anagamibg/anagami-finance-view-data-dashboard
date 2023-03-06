<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;

/**
 * Lookup for analysis dimensions.
 *
 * @property int $id
 * @property int $company_id
 * @property string $type region|client_segment|product_group|cost_center
 * @property string $name
 * @property string|null $code
 */
class FinDimension extends BaseModel
{

    use HasCompany;

    const TYPES = ['region', 'client_segment', 'product_group', 'cost_center'];

    protected $table = 'fin_dimensions';
    protected $guarded = ['id'];

}

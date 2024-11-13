<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;

/**
 * First-time setup wizard state of a company (one row per company).
 * steps: {"company": "done|pending|skipped", "entities": ..., "chart": ..., "import": ..., "dashboard": ...}
 *
 * @property int $id
 * @property int $company_id
 * @property array|null $steps
 * @property string|null $chart_template nss|micro
 * @property \Illuminate\Support\Carbon|null $completed_at
 * @property \Illuminate\Support\Carbon|null $dismissed_at
 * @property int|null $created_by
 */
class FinOnboarding extends BaseModel
{

    use HasCompany;

    const STEPS = ['company', 'entities', 'chart', 'import', 'dashboard'];

    protected $table = 'fin_onboarding';
    protected $guarded = ['id'];
    protected $casts = ['steps' => 'array', 'completed_at' => 'datetime', 'dismissed_at' => 'datetime'];

}

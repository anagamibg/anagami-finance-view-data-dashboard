<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\DB;

/**
 * Chart of accounts item.
 *
 * @property int $id
 * @property int $company_id
 * @property string $code
 * @property string $name
 * @property string $type asset|liability|equity|revenue|expense
 * @property string|null $subtype
 * @property int|null $parent_id
 * @property bool $is_active
 */
class FinAccount extends BaseModel
{

    use HasCompany;

    const TYPES = ['asset', 'liability', 'equity', 'revenue', 'expense'];

    /** Contra accounts: a positive amount reduces the balance of its type (e.g. accumulated depreciation). */
    const CONTRA_CODES = ['241'];

    protected $table = 'fin_accounts';
    protected $guarded = ['id'];
    protected $casts = ['is_active' => 'boolean'];

    public function parent(): BelongsTo
    {
        return $this->belongsTo(self::class, 'parent_id');
    }

    public function entries(): HasMany
    {
        return $this->hasMany(FinEntry::class, 'account_id');
    }

    public function getIsContraAttribute(): bool
    {
        return self::isContraCode((string)$this->code, $this->parent_id && $this->relationLoaded('parent') ? (string)$this->parent?->code : null);
    }

    /**
     * Review A: is the account a contra account - one of CONTRA_CODES or an analytic account of one. A code counts as
     * contra when it is in CONTRA_CODES or starts with a contra synthetic code (2411 / 241-1 under 241); $syntheticCode
     * (the synthetic or parent account of a free code such as "A1") is checked the same way.
     */
    public static function isContraCode(string $code, ?string $syntheticCode = null): bool
    {
        foreach ([$code, (string)$syntheticCode] as $c) {
            if ($c === '') {
                continue;
            }

            if (in_array($c, self::CONTRA_CODES, true)
                || (preg_match('/^(\d{3})./', $c, $m) && in_array($m[1], self::CONTRA_CODES, true))) {
                return true;
            }
        }

        return false;
    }

    /**
     * Review A: contra flag of every account of a chart, following parent_id to any depth (a free code "A1" under 2411
     * under 241 is contra too).
     *
     * @param iterable<object|array{id: int, code: string, parent_id: ?int}> $accounts the whole chart of one company
     * @return array<int, bool> account id => contra
     */
    public static function contraMap(iterable $accounts): array
    {
        $byId = [];

        foreach ($accounts as $a) {
            $a = (object)$a;
            $byId[(int)$a->id] = ['code' => (string)$a->code, 'parent_id' => $a->parent_id ? (int)$a->parent_id : null];
        }

        $out = [];

        foreach ($byId as $id => $a) {
            $contra = false;
            $seen = [];

            for ($cur = $id; $cur !== null && isset($byId[$cur]) && !isset($seen[$cur]); $cur = $byId[$cur]['parent_id']) {
                $seen[$cur] = true;

                if (self::isContraCode($byId[$cur]['code'])) {
                    $contra = true;
                    break;
                }
            }

            $out[$id] = $contra;
        }

        return $out;
    }

    /** @return array<int, bool> account id => contra, for the whole chart of the company (see contraMap()) */
    public static function contraMapFor(int $companyId): array
    {
        return self::contraMap(DB::table('fin_accounts')->where('company_id', $companyId)->get(['id', 'code', 'parent_id']));
    }

}

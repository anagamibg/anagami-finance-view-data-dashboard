<?php

namespace App\Models\Databackd;

use App\Models\BaseModel;
use App\Traits\HasCompany;
use Illuminate\Support\Str;

/**
 * API token for POST /api/databackd/fin-entries. Only the hash is stored; the plain token is shown once at creation.
 *
 * @property int $id
 * @property int $company_id
 * @property string $name
 * @property string $token_hash
 * @property string $last_four
 * @property \Illuminate\Support\Carbon|null $last_used_at
 * @property \Illuminate\Support\Carbon|null $revoked_at
 * @property int|null $created_by
 */
class FinApiToken extends BaseModel
{

    use HasCompany;

    protected $table = 'fin_api_tokens';
    protected $guarded = ['id'];
    protected $hidden = ['token_hash'];
    protected $casts = ['last_used_at' => 'datetime', 'revoked_at' => 'datetime'];

    /**
     * Create a token for the current company. Returns [model, plainToken].
     *
     * @return array{0: FinApiToken, 1: string}
     */
    public static function generate(string $name, ?int $userId = null, ?int $companyId = null): array
    {
        $plain = 'dbk_' . Str::random(40);

        $token = new static();
        $token->company_id = $companyId ?: company()->id;
        $token->name = $name;
        $token->token_hash = static::hashToken($plain);
        $token->last_four = substr($plain, -4);
        $token->created_by = $userId;
        $token->save();

        return [$token, $plain];
    }

    public static function hashToken(string $plain): string
    {
        return hash('sha256', $plain);
    }

    public function getIsActiveAttribute(): bool
    {
        return $this->revoked_at === null;
    }

}

<?php

namespace App\Http\Middleware\Databackd;

use App\Models\Databackd\FinApiToken;
use App\Services\Databackd\Data\FinApiAudit;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Symfony\Component\HttpFoundation\Response;

/**
 * Authenticates POST /api/databackd/fin-entries with "Authorization: Bearer dbk_...".
 * Stateless: no session / auth guard. On success the token and its company id are stored in the request attributes
 * (fin_api_token, fin_company_id) - the company ALWAYS comes from the token, never from the request.
 *
 * Security (S6/P6):
 *  - the credential is read from the Authorization header only (never from the query string / body: those end up in logs and Referer);
 *    it must look exactly like a token ("dbk_" + 32-64 alphanumerics), anything else (arrays, empty, huge values) is 401 without a DB query;
 *  - the token is found by the SHA-256 hash of the presented value (unique index): no string comparison of secrets in PHP/SQL happens
 *    on attacker-controlled data, and the hash is re-checked with hash_equals();
 *  - revoked and unknown tokens are indistinguishable (same query, same 401 body, WWW-Authenticate: Bearer);
 *  - failed attempts are counted per client IP (databackd-api.rate.failed_per_ip_per_minute); an IP over the limit gets 429 whatever
 *    it sends, so a valid token cannot be told from an invalid one by a locked-out client;
 *  - a valid token is limited per minute (databackd-api.rate.per_token_per_minute) -> 429 + Retry-After;
 *  - the company must be active; every failure and every rejection is written to the API audit log (never the token itself).
 */
class FinApiTokenAuth
{

    /** Kept for compatibility; the effective limit is config('databackd-api.rate.per_token_per_minute'). */
    public const MAX_ATTEMPTS_PER_MINUTE = 60;

    public const TOKEN_PATTERN = '/^dbk_[A-Za-z0-9]{32,64}$/';

    public function handle(Request $request, Closure $next)
    {
        $ip = (string)$request->ip();
        $failKey = 'databackd-fin-api:fail:' . $ip;
        $maxFailures = (int)config('databackd-api.rate.failed_per_ip_per_minute', 10);

        if (RateLimiter::tooManyAttempts($failKey, $maxFailures)) {
            FinApiAudit::failure('ip_blocked', $request);

            return $this->tooManyRequests(RateLimiter::availableIn($failKey));
        }

        [$plain, $reason] = $this->presentedToken($request);
        $token = $plain !== null ? $this->findToken($plain) : null;

        if (!$token) {
            RateLimiter::hit($failKey, 60);
            FinApiAudit::failure('auth_failed', $request, ['reason' => $plain === null ? $reason : 'unknown_or_revoked', 'presented' => FinApiAudit::fingerprint($plain)]);

            return $this->unauthenticated($reason !== 'missing');
        }

        $companyStatus = DB::table('companies')->where('id', $token->company_id)->value('status');

        if ($companyStatus !== 'active') {
            FinApiAudit::failure('company_inactive', $request, ['token_id' => $token->id, 'company_id' => (int)$token->company_id]);

            return response()->json(['message' => 'The company account is not active.'], 403);
        }

        $key = 'databackd-fin-api:token:' . $token->id;
        $perMinute = (int)config('databackd-api.rate.per_token_per_minute', self::MAX_ATTEMPTS_PER_MINUTE);

        if (RateLimiter::tooManyAttempts($key, $perMinute)) {
            FinApiAudit::failure('token_throttled', $request, ['token_id' => $token->id, 'company_id' => (int)$token->company_id]);

            return $this->tooManyRequests(RateLimiter::availableIn($key));
        }

        RateLimiter::hit($key, 60);

        // query update: do not touch updated_at and do not depend on the tenant scope; at most once a minute (one write per request is not needed)
        if ($token->last_used_at === null || $token->last_used_at->diffInSeconds(now(), true) >= 60) {
            FinApiToken::withoutGlobalScopes()->whereKey($token->id)->update(['last_used_at' => now()]);
        }

        $request->attributes->set('fin_api_token', $token);
        $request->attributes->set('fin_company_id', (int)$token->company_id);

        /** @var Response $response */
        $response = $next($request);

        $response->headers->set('X-RateLimit-Limit', (string)$perMinute);
        $response->headers->set('X-RateLimit-Remaining', (string)RateLimiter::remaining($key, $perMinute));

        return $response;
    }

    /**
     * The credential presented in "Authorization: Bearer <token>" - [token|null, reason].
     *
     * @return array{0: ?string, 1: string}
     */
    protected function presentedToken(Request $request): array
    {
        $header = $request->headers->get('Authorization');

        if (!is_string($header) || $header === '') {
            return [null, 'missing'];
        }

        // 200 chars is far more than "Bearer " + a token; the length check also keeps the regex away from huge values
        if (strlen($header) > 200 || !preg_match('/^Bearer +(\S+)$/i', $header, $m)) {
            return [null, 'malformed'];
        }

        return preg_match(self::TOKEN_PATTERN, $m[1]) === 1 ? [$m[1], 'ok'] : [null, 'malformed'];
    }

    protected function findToken(string $plain): ?FinApiToken
    {
        $hash = FinApiToken::hashToken($plain);

        $token = FinApiToken::withoutGlobalScopes()
            ->where('token_hash', $hash)
            ->whereNull('revoked_at')
            ->first();

        return $token && hash_equals((string)$token->token_hash, $hash) ? $token : null;
    }

    /**
     * RFC 6750 section 3: no error code when no credential was sent, error="invalid_token" when one was (malformed, unknown or revoked).
     */
    protected function unauthenticated(bool $presented = false): Response
    {
        $challenge = 'Bearer realm="databackd"' . ($presented ? ', error="invalid_token"' : '');

        return response()->json(['message' => 'Unauthenticated'], 401, ['WWW-Authenticate' => $challenge]);
    }

    protected function tooManyRequests(int $retryAfter): Response
    {
        return response()->json(['message' => 'Too Many Requests'], 429, ['Retry-After' => max(1, $retryAfter)]);
    }

}

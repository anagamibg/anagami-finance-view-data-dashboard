<?php

namespace App\Services\Databackd\Data;

use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Audit log of the REST API (POST /api/databackd/fin-entries) and of the API token lifecycle.
 *
 * Own daily file storage/logs/databackd-api-YYYY-MM-DD.log (built on the fly, so no config:cache is needed).
 * Never logs a token: failures carry only a short SHA-256 fingerprint of what was presented, successes the token id.
 * Failure lines are capped per minute so a flood cannot grow the log without bound.
 */
class FinApiAudit
{

    /** Failure lines written per minute (all clients together). */
    const MAX_FAILURE_LINES_PER_MINUTE = 120;

    public static function success(string $event, Request $request, array $context = []): void
    {
        self::write('info', $event, $request, $context);
    }

    /**
     * Failed or rejected call (bad token, throttling, rejected body ...). Rate-limited.
     */
    public static function failure(string $event, Request $request, array $context = []): void
    {
        try {
            if (!RateLimiter::attempt('databackd-fin-api:failure-log', self::MAX_FAILURE_LINES_PER_MINUTE, fn() => true, 60)) {
                return;
            }
        } catch (\Throwable $e) {
            // the limiter must never break the API
        }

        self::write('warning', $event, $request, $context);
    }

    /**
     * Short non-reversible fingerprint of a presented credential (first 8 hex chars of its SHA-256).
     */
    public static function fingerprint(?string $presented): ?string
    {
        return $presented === null || $presented === '' ? null : substr(hash('sha256', $presented), 0, 8);
    }

    protected static function write(string $level, string $event, Request $request, array $context): void
    {
        try {
            $context = [
                'event' => $event,
                'ip' => $request->ip(),
                'method' => $request->method(),
                'path' => $request->path(),
                'user_agent' => mb_substr((string)$request->userAgent(), 0, 120),
            ] + $context;

            Log::build([
                'driver' => 'daily',
                'path' => storage_path('logs/databackd-api.log'),
                'level' => 'info',
                'days' => 90,
            ])->log($level, 'databackd.api', $context);
        } catch (\Throwable $e) {
            // logging must never break the API
        }
    }

}

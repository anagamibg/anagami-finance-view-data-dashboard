<?php

namespace App\Http\Middleware\Databackd;

use App\Services\Databackd\Data\FinApiAudit;
use App\Services\Databackd\Data\FinApiExceptionHandler;
use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Foundation\Http\Middleware\ConvertEmptyStringsToNull;
use Illuminate\Foundation\Http\Middleware\TrimStrings;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * AFV (S6/P6): GLOBAL middleware (app/Http/Kernel.php $middleware, right after TrustProxies) that guards the stateless
 * REST API under /api/databackd/* BEFORE anything parses the request body or routes it:
 *
 *  - HTTPS only when APP_URL is https:// (the bearer token must not travel in clear text; switch: databackd-api.require_https);
 *  - the only method of an endpoint is accepted (otherwise 405 + Allow). Without this the Froiden API exception handler
 *    turns the router's MethodNotAllowed into HTTP 500 (with a stack trace when APP_DEBUG=true);
 *  - OPTIONS / CORS: this API is server-to-server, no CORS header is ever sent (preflights are answered without Access-Control-*,
 *    and the headers added further down the stack by HandleCors / ForceCorsForApi are removed from the response);
 *  - the declared Content-Length is capped (413) before TrimStrings / XSS / the controller parse the JSON, and TrimStrings /
 *    ConvertEmptyStringsToNull are switched off for this API (the controller reads the raw body; walking ~1M JSON values there
 *    before authentication only burns CPU).
 *
 *  - errors of this API are rendered by FinApiExceptionHandler (generic JSON): the Froiden API exception handler returns the
 *    exception message and the full stack trace with arguments when APP_DEBUG=true (and crashes with "Undefined variable $response"
 *    when it is false), so it must never render an error of this API.
 *
 * Authentication, rate limiting and body validation are done by FinApiTokenAuth and FinEntryApiController.
 */
class FinApiGate
{

    /** Endpoint path => the single method it accepts. Other paths under api/databackd/* fall through to the router (404). */
    public const ENDPOINTS = [
        'api/databackd/fin-entries' => 'POST',
    ];

    private const CORS_HEADERS = [
        'Access-Control-Allow-Origin',
        'Access-Control-Allow-Credentials',
        'Access-Control-Allow-Methods',
        'Access-Control-Allow-Headers',
        'Access-Control-Expose-Headers',
        'Access-Control-Max-Age',
    ];

    public function handle(Request $request, Closure $next)
    {
        if (!$request->is('api/databackd/*')) {
            return $next($request);
        }

        if ($this->httpsRequired() && !$request->isSecure()) {
            FinApiAudit::failure('https_required', $request);

            return $this->finish($this->json(['message' => 'HTTPS is required for this API.'], 403));
        }

        if ($request->isMethod('OPTIONS')) {
            return $this->finish(response('', 204, ['Allow' => 'POST']));
        }

        $allowed = self::ENDPOINTS[trim($request->path(), '/')] ?? null;

        if ($allowed !== null && !$request->isMethod($allowed)) {
            return $this->finish($this->json(['message' => 'Method not allowed.'], 405, ['Allow' => $allowed]));
        }

        $maxBytes = (int)config('databackd-api.max_body_bytes', 2 * 1024 * 1024);

        if ((int)$request->server('CONTENT_LENGTH', 0) > $maxBytes) {
            FinApiAudit::failure('body_too_large', $request, ['content_length' => (int)$request->server('CONTENT_LENGTH')]);

            return $this->finish($this->json(['message' => 'The request body is too large (max ' . $maxBytes . ' bytes).'], 413));
        }

        $this->skipBodyTransforms();

        // errors of this API are rendered by a handler that never shows a message or a trace (see FinApiExceptionHandler)
        app()->singleton(ExceptionHandler::class, FinApiExceptionHandler::class);

        return $this->finish($next($request));
    }

    /** Registered once per process; TrimStrings / ConvertEmptyStringsToNull run after this middleware. */
    protected function skipBodyTransforms(): void
    {
        static $registered = false;

        if ($registered) {
            return;
        }

        $registered = true;
        $skip = fn(Request $request) => $request->is('api/databackd/*');

        TrimStrings::skipWhen($skip);
        ConvertEmptyStringsToNull::skipWhen($skip);
    }

    protected function httpsRequired(): bool
    {
        return (bool)config('databackd-api.require_https', true)
            && str_starts_with(strtolower((string)config('app.url')), 'https://');
    }

    protected function json(array $data, int $status, array $headers = []): Response
    {
        return response()->json($data, $status, $headers);
    }

    /**
     * No CORS for this API, never cached, never sniffed.
     */
    protected function finish(Response $response): Response
    {
        foreach (self::CORS_HEADERS as $header) {
            $response->headers->remove($header);
        }

        $response->headers->set('Cache-Control', 'no-store');
        $response->headers->set('X-Content-Type-Options', 'nosniff');

        return $response;
    }

}

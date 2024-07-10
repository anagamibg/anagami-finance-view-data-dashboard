<?php

namespace App\Services\Databackd\Data;

use App\Exceptions\Handler;
use Illuminate\Auth\AuthenticationException;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\HttpExceptionInterface;
use Throwable;

/**
 * AFV (S6/P6): exception handler used ONLY for /api/databackd/* (bound by App\Http\Middleware\Databackd\FinApiGate).
 *
 * The application handler on api/* is Froiden\RestAPI\Handlers\ApiExceptionHandler: it answers every exception with HTTP 500, puts
 * the exception message and the whole stack trace (with call arguments) into the JSON when APP_DEBUG=true, and crashes with
 * "Undefined variable $response" when APP_DEBUG=false. Here every error is a short generic JSON; the details only go to the log
 * (report() is the parent's, so Sentry / storage/logs still get the full exception).
 */
class FinApiExceptionHandler extends Handler
{

    public function render($request, Throwable $e)
    {
        // S12: the framework's own mapping first (TokenMismatch -> 419, ModelNotFound / SuspiciousOperation -> 404,
        // AuthorizationException -> 403, malformed request -> 400) - these were answered with a generic 500 before
        $e = $this->prepareException($this->mapException($e));

        if ($e instanceof AuthenticationException) {
            return response()->json(['message' => 'Unauthenticated'], 401, ['WWW-Authenticate' => 'Bearer realm="databackd"']);
        }

        if ($e instanceof ValidationException) {
            return response()->json(['message' => Response::$statusTexts[422]], 422);
        }

        if ($e instanceof HttpExceptionInterface) {
            $status = $e->getStatusCode();

            return response()->json(['message' => Response::$statusTexts[$status] ?? ($status === 419 ? 'Page Expired' : 'Error')], $status, $e->getHeaders());
        }

        return response()->json(['message' => 'Server error.'], 500);
    }

}

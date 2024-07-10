<?php

use App\Http\Controllers\Databackd\Data\Api\FinEntryApiController;
use App\Http\Middleware\Databackd\FinApiTokenAuth;
use App\Http\Middleware\Databackd\RequireCompanyModule;
use Illuminate\Support\Facades\Route;
use Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful;

// P8 REST API (loaded with the "api" middleware group and the "api" prefix) -> POST /api/databackd/fin-entries
// AFV (S8): the API belongs to the databackd_integrations package module (checked after the token identified the company)
// S12: the token API is stateless. Sanctum's EnsureFrontendRequestsAreStateful (in the "api" group) starts a session and verifies a
// CSRF token whenever Origin/Referer is the app's own host (or localhost) - such a call then failed with HTTP 500 (TokenMismatch).
Route::prefix('databackd')
    ->withoutMiddleware([EnsureFrontendRequestsAreStateful::class])
    ->middleware([FinApiTokenAuth::class, RequireCompanyModule::class . ':databackd_integrations'])
    ->group(function () {
        Route::post('fin-entries', [FinEntryApiController::class, 'store'])->name('databackd.api.fin-entries.store');
    });

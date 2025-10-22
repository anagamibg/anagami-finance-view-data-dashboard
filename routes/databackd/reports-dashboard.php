<?php

use App\Http\Controllers\Databackd\Reports\FinDashboardController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/reports/dashboard')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_reports')->name('databackd.reports.dashboard.')->group(function () {
    Route::get('/', [FinDashboardController::class, 'index'])->name('index');
});

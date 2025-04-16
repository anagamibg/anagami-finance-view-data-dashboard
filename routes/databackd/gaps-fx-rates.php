<?php

use App\Http\Controllers\Databackd\Gaps\FxRates\FxRateController;
use Illuminate\Support\Facades\Route;

// AFV (S8) / B6: exchange rates by date (manual + ECB), used by the consolidation and the import (amount_base).
// Loaded with prefix "account" and the standard auth middleware (see RouteServiceProvider).

Route::prefix('databackd/gaps/fx-rates')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.gaps.fx-rates.')->group(function () {
    Route::get('/', [FxRateController::class, 'index'])->name('index');
    Route::get('chart', [FxRateController::class, 'chart'])->name('chart');
    Route::get('create', [FxRateController::class, 'create'])->name('create');
    Route::post('/', [FxRateController::class, 'store'])->name('store');
    Route::post('fetch-ecb', [FxRateController::class, 'fetchEcb'])->name('fetch-ecb');
    Route::get('{rate}/edit', [FxRateController::class, 'edit'])->whereNumber('rate')->name('edit');
    Route::put('{rate}', [FxRateController::class, 'update'])->whereNumber('rate')->name('update');
    Route::delete('{rate}', [FxRateController::class, 'destroy'])->whereNumber('rate')->name('destroy');
});

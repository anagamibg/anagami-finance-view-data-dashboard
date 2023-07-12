<?php

use App\Http\Controllers\Databackd\Data\FinEntryBulkController;
use Illuminate\Support\Facades\Route;

// AFV (S8): needs the databackd_data package module
Route::middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->group(function () {
    Route::get('databackd/data/bulk', [FinEntryBulkController::class, 'index'])->name('databackd.data.bulk.index');
    Route::get('databackd/data/bulk/load', [FinEntryBulkController::class, 'load'])->name('databackd.data.bulk.load');
    Route::post('databackd/data/bulk/store', [FinEntryBulkController::class, 'store'])->name('databackd.data.bulk.store');
});

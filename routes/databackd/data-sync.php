<?php

use App\Http\Controllers\Databackd\Data\FinSyncController;
use Illuminate\Support\Facades\Route;

// P9: transfer of paid invoices and approved expenses into fin_entries (URLs: /account/databackd/data/sync/...)
Route::prefix('databackd/data/sync')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->group(function () {
    Route::get('/', [FinSyncController::class, 'index'])->name('databackd.data.sync.index');
    Route::post('run', [FinSyncController::class, 'run'])->name('databackd.data.sync.run');
});

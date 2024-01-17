<?php

use App\Http\Controllers\Databackd\Data\FinImportBatchController;
use Illuminate\Support\Facades\Route;

// Import history (loaded with prefix "account"): /account/databackd/data/batches
Route::prefix('databackd/data/batches')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.data.batches.')->group(function () {
    Route::get('/', [FinImportBatchController::class, 'index'])->name('index');
    Route::get('{batch}', [FinImportBatchController::class, 'show'])->whereNumber('batch')->name('show');
    Route::get('{batch}/errors-export', [FinImportBatchController::class, 'errorsExport'])->whereNumber('batch')->name('errors-export');
    Route::post('{batch}/undo', [FinImportBatchController::class, 'undo'])->whereNumber('batch')->name('undo');
});

<?php

use App\Http\Controllers\Databackd\Data\FinImportController;
use Illuminate\Support\Facades\Route;

/*
 * AFV – data import wizard (Excel / CSV -> fin_entries).
 * Loaded automatically with the "web, auth, ..." middleware and the "account" prefix (see RouteServiceProvider::mapDatabackdRoutes).
 */
Route::prefix('databackd/data/import')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.data.import.')->group(function () {
    Route::get('/', [FinImportController::class, 'index'])->name('index');                       // step 1: upload
    Route::post('upload', [FinImportController::class, 'upload'])->name('upload');
    Route::get('mapping', [FinImportController::class, 'mapping'])->name('mapping');             // step 2: map columns (?token=<uuid>)
    Route::post('preview', [FinImportController::class, 'preview'])->name('preview');           // AJAX validation of the first 10 rows
    Route::post('run', [FinImportController::class, 'run'])->name('run');                       // start the import
    Route::get('progress/{batch}', [FinImportController::class, 'progress'])->name('progress'); // JSON progress
    Route::get('result/{batch}', [FinImportController::class, 'result'])->name('result');       // step 3: progress + result
    Route::get('template', [FinImportController::class, 'template'])->name('template');         // ?format=xlsx|csv
});

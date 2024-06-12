<?php

use App\Http\Controllers\Databackd\Data\FinDataSourceController;
use Illuminate\Support\Facades\Route;

// P6: Google Sheets / CSV URL import and saved data sources. URL prefix: /account/databackd/data/sources
Route::prefix('databackd/data/sources')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_integrations')->name('databackd.data.sources.')->group(function () {
    Route::get('/', [FinDataSourceController::class, 'index'])->name('index');
    Route::post('fetch', [FinDataSourceController::class, 'fetch'])->name('fetch');
    Route::get('mapping/{token?}', [FinDataSourceController::class, 'mapping'])->name('mapping');
    Route::post('preview', [FinDataSourceController::class, 'preview'])->name('preview');
    Route::post('import', [FinDataSourceController::class, 'import'])->name('import');
    Route::get('result/{batch}', [FinDataSourceController::class, 'result'])->whereNumber('batch')->name('result');
    Route::post('store', [FinDataSourceController::class, 'store'])->name('store');
    Route::post('{source}/sync', [FinDataSourceController::class, 'sync'])->whereNumber('source')->name('sync');
    Route::delete('{source}', [FinDataSourceController::class, 'destroy'])->whereNumber('source')->name('destroy');
});

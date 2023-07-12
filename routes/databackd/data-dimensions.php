<?php

use App\Http\Controllers\Databackd\Data\FinDimensionController;
use App\Http\Controllers\Databackd\Data\FinEntityController;
use Illuminate\Support\Facades\Route;

// AFV Data / P4: dimensions (regions, segments, product groups, cost centers) and legal entities.
// Loaded with prefix "account" and the standard auth middleware (see RouteServiceProvider).

Route::prefix('databackd/data')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.data.')->group(function () {
    Route::get('dimensions', [FinDimensionController::class, 'index'])->name('dimensions.index');
    Route::get('dimensions/create', [FinDimensionController::class, 'create'])->name('dimensions.create');
    Route::post('dimensions', [FinDimensionController::class, 'store'])->name('dimensions.store');
    Route::get('dimensions/{dimension}/edit', [FinDimensionController::class, 'edit'])->whereNumber('dimension')->name('dimensions.edit');
    Route::put('dimensions/{dimension}', [FinDimensionController::class, 'update'])->whereNumber('dimension')->name('dimensions.update');
    Route::delete('dimensions/{dimension}', [FinDimensionController::class, 'destroy'])->whereNumber('dimension')->name('dimensions.destroy');

    Route::get('entities', [FinEntityController::class, 'index'])->name('entities.index');
    Route::get('entities/create', [FinEntityController::class, 'create'])->name('entities.create');
    Route::post('entities', [FinEntityController::class, 'store'])->name('entities.store');
    Route::get('entities/{entity}/edit', [FinEntityController::class, 'edit'])->whereNumber('entity')->name('entities.edit');
    Route::put('entities/{entity}', [FinEntityController::class, 'update'])->whereNumber('entity')->name('entities.update');
    Route::delete('entities/{entity}', [FinEntityController::class, 'destroy'])->whereNumber('entity')->name('entities.destroy');
});

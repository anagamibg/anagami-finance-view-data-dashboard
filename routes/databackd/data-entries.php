<?php

use App\Http\Controllers\Databackd\Data\FinEntryController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
Route::prefix('databackd/data/entries')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.data.entries.')->group(function () {
    Route::get('export', [FinEntryController::class, 'export'])->name('export');
    Route::post('apply-quick-action', [FinEntryController::class, 'applyQuickAction'])->name('apply_quick_action');
    Route::get('create', [FinEntryController::class, 'create'])->name('create');
    Route::get('/', [FinEntryController::class, 'index'])->name('index');
    Route::post('/', [FinEntryController::class, 'store'])->name('store');
    Route::get('{id}/edit', [FinEntryController::class, 'edit'])->whereNumber('id')->name('edit');
    Route::put('{id}', [FinEntryController::class, 'update'])->whereNumber('id')->name('update');
    Route::delete('{id}', [FinEntryController::class, 'destroy'])->whereNumber('id')->name('destroy');
});

<?php

use App\Http\Controllers\Databackd\Data\FinAccountController;
use Illuminate\Support\Facades\Route;

Route::prefix('databackd/data/accounts')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.data.accounts.')->group(function () {
    Route::get('/', [FinAccountController::class, 'index'])->name('index');
    Route::get('create', [FinAccountController::class, 'create'])->name('create');
    Route::post('/', [FinAccountController::class, 'store'])->name('store');
    Route::get('{account}/edit', [FinAccountController::class, 'edit'])->whereNumber('account')->name('edit');
    Route::put('{account}', [FinAccountController::class, 'update'])->whereNumber('account')->name('update');
    Route::post('{account}/toggle', [FinAccountController::class, 'toggle'])->whereNumber('account')->name('toggle');
    Route::delete('{account}', [FinAccountController::class, 'destroy'])->whereNumber('account')->name('destroy');
});

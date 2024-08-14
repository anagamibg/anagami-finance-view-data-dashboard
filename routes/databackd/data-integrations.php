<?php

use App\Http\Controllers\Databackd\Data\FinApiTokenController;
use App\Http\Controllers\Databackd\Data\IntegrationController;
use Illuminate\Support\Facades\Route;

// P8 integrations (loaded with the "account" prefix) -> /account/databackd/data/integrations
Route::prefix('databackd/data/integrations')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_integrations')->group(function () {
    Route::get('/', [IntegrationController::class, 'index'])->name('databackd.data.integrations.index');
    Route::post('tokens', [FinApiTokenController::class, 'store'])->name('databackd.data.tokens.store');
    Route::delete('tokens/{id}', [FinApiTokenController::class, 'destroy'])->whereNumber('id')->name('databackd.data.tokens.destroy');
});

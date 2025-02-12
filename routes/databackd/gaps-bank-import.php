<?php

use App\Http\Controllers\Databackd\Gaps\BankImport\BankImportController;
use App\Http\Controllers\Databackd\Gaps\BankImport\BankStatementController;
use App\Http\Controllers\Databackd\Gaps\BankImport\CounterpartyMappingController;
use Illuminate\Support\Facades\Route;

/*
 * AFV (S8 / B1a) - import of bank statements (MT940, CAMT.053) into fin_entries, with a review and
 * rules that book the counterparties to accounts. Loaded automatically with the "account" prefix and the standard auth middleware.
 */
Route::prefix('databackd/gaps/bank-import')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.gaps.bank-import.')->group(function () {
    // wizard: upload -> review -> result
    Route::get('/', [BankImportController::class, 'index'])->name('index');
    Route::post('upload', [BankImportController::class, 'upload'])->name('upload');
    Route::get('review', [BankImportController::class, 'review'])->name('review');            // ?token=<uuid>[&entity_id=]
    Route::post('run', [BankImportController::class, 'run'])->name('run');
    Route::get('result/{batch}', [BankImportController::class, 'result'])->whereNumber('batch')->name('result');
    Route::get('samples/{format}', [BankImportController::class, 'sample'])->whereIn('format', ['mt940', 'camt053'])->name('sample');

    // imported statements
    Route::get('statements', [BankStatementController::class, 'index'])->name('statements');
    Route::post('statements/{batch}/undo', [BankStatementController::class, 'undo'])->whereNumber('batch')->name('statements.undo');

    // counterparty -> account rules
    Route::get('rules', [CounterpartyMappingController::class, 'index'])->name('rules.index');
    Route::get('rules/create', [CounterpartyMappingController::class, 'create'])->name('rules.create');
    Route::post('rules', [CounterpartyMappingController::class, 'store'])->name('rules.store');
    Route::get('rules/{rule}/edit', [CounterpartyMappingController::class, 'edit'])->whereNumber('rule')->name('rules.edit');
    Route::put('rules/{rule}', [CounterpartyMappingController::class, 'update'])->whereNumber('rule')->name('rules.update');
    Route::delete('rules/{rule}', [CounterpartyMappingController::class, 'destroy'])->whereNumber('rule')->name('rules.destroy');
});

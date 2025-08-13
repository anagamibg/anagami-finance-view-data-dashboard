<?php

use App\Http\Controllers\Databackd\Gaps\Alerts\AlertHistoryController;
use App\Http\Controllers\Databackd\Gaps\Alerts\AlertRuleController;
use Illuminate\Support\Facades\Route;

// Loaded by RouteServiceProvider::mapDatabackdRoutes() with the "account" prefix and the web/auth middleware.
// B4 (S8): alerts at thresholds - rules, "check now" and the history of the alerts (the check itself is `databackd:check-alerts`).
Route::prefix('databackd/gaps/alerts')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_analytics')->name('databackd.gaps.alerts.')->group(function () {
    Route::get('/', [AlertRuleController::class, 'index'])->name('index');
    Route::get('create', [AlertRuleController::class, 'create'])->name('create');
    Route::post('/', [AlertRuleController::class, 'store'])->name('store');
    Route::post('check', [AlertRuleController::class, 'check'])->name('check');

    Route::get('history', [AlertHistoryController::class, 'index'])->name('history');
    Route::post('history/acknowledge', [AlertHistoryController::class, 'acknowledgeAll'])->name('acknowledge-all');
    Route::post('history/{event}/acknowledge', [AlertHistoryController::class, 'acknowledge'])->whereNumber('event')->name('acknowledge');

    Route::get('{rule}/edit', [AlertRuleController::class, 'edit'])->whereNumber('rule')->name('edit');
    Route::put('{rule}', [AlertRuleController::class, 'update'])->whereNumber('rule')->name('update');
    Route::post('{rule}/toggle', [AlertRuleController::class, 'toggle'])->whereNumber('rule')->name('toggle');
    Route::delete('{rule}', [AlertRuleController::class, 'destroy'])->whereNumber('rule')->name('destroy');
});

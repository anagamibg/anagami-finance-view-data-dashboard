<?php

use App\Http\Controllers\Databackd\Gaps\Onboarding\OnboardingController;
use App\Models\Databackd\FinOnboarding;
use Illuminate\Support\Facades\Route;

// AFV (S8 / B5): setup wizard for a new company. URL prefix: /account/databackd/gaps/onboarding
Route::prefix('databackd/gaps/onboarding')->middleware(\App\Http\Middleware\Databackd\RequireModule::class . ':databackd_data')->name('databackd.gaps.onboarding.')->group(function () {
    Route::get('/', [OnboardingController::class, 'index'])->name('index');                      // ?step=1..5
    Route::post('company', [OnboardingController::class, 'saveCompany'])->name('company');      // step 1
    Route::post('entities', [OnboardingController::class, 'saveEntities'])->name('entities');   // step 2
    Route::post('chart', [OnboardingController::class, 'saveChart'])->name('chart');            // step 3
    Route::post('step/{step}', [OnboardingController::class, 'markStep'])->whereIn('step', array_slice(FinOnboarding::STEPS, 0, -1))->name('step'); // done|skipped
    Route::post('complete', [OnboardingController::class, 'complete'])->name('complete');       // step 5
    Route::post('dismiss', [OnboardingController::class, 'dismiss'])->name('dismiss');
    Route::post('restore', [OnboardingController::class, 'restore'])->name('restore');
});

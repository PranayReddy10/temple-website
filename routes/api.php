<?php

use App\Http\Controllers\Api\V1\DeityController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\StateController;
use App\Http\Controllers\Api\V1\TempleCategoryController;
use App\Http\Controllers\Api\V1\TempleController;
use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Public API v1
|--------------------------------------------------------------------------
|
| Read-only endpoints consumed by the Flutter app. Everything here is public
| and unauthenticated; user accounts and write endpoints arrive with the
| Passport slice.
|
| The version prefix is not decoration. The app ships to devices we cannot
| update on demand, so a released client must keep working against a frozen
| contract while v2 evolves alongside it.
|
| Only published temples are ever exposed — enforced in the controller query,
| not left to a caller-supplied filter.
|
*/

Route::prefix('v1')->name('api.v1.')->group(function (): void {
    Route::get('temples', [TempleController::class, 'index'])->name('temples.index');
    Route::get('temples/{temple:slug}', [TempleController::class, 'show'])->name('temples.show');

    Route::get('deities', [DeityController::class, 'index'])->name('deities.index');
    Route::get('categories', [TempleCategoryController::class, 'index'])->name('categories.index');
    Route::get('states', [StateController::class, 'index'])->name('states.index');
    Route::get('facilities', [FacilityController::class, 'index'])->name('facilities.index');
});

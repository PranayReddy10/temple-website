<?php

use App\Http\Controllers\Api\V1\Auth\DevoteeAuthController;
use App\Http\Controllers\Api\V1\DeityController;
use App\Http\Controllers\Api\V1\DevoteeProfileController;
use App\Http\Controllers\Api\V1\DevotionalDayController;
use App\Http\Controllers\Api\V1\EventController;
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

    Route::get('events', [EventController::class, 'index'])->name('events.index');

    // Day-wise devotional content: Monday Shiva, Tuesday Hanuman, and so on.
    Route::get('today', [DevotionalDayController::class, 'today'])->name('today');
    Route::get('days', [DevotionalDayController::class, 'index'])->name('days.index');
    Route::get('days/{weekday}', [DevotionalDayController::class, 'show'])
        ->whereNumber('weekday')
        ->name('days.show');

    /*
    |--------------------------------------------------------------------------
    | Devotee accounts
    |--------------------------------------------------------------------------
    |
    | App users, authenticated with Sanctum tokens against the 'devotee'
    | guard. That guard resolves the devotees table, which has no role column
    | and no relationship to the admin panels — a devotee token cannot reach
    | staff functionality because the staff guard does not know this model.
    |
    | Registration and login are rate limited harder than the read endpoints:
    | they create records and check credentials.
    |
    */
    Route::prefix('auth')->name('auth.')->group(function (): void {
        Route::post('register', [DevoteeAuthController::class, 'register'])
            ->middleware('throttle:6,1')
            ->name('register');

        Route::post('login', [DevoteeAuthController::class, 'login'])
            ->middleware('throttle:6,1')
            ->name('login');

        Route::post('logout', [DevoteeAuthController::class, 'logout'])
            ->middleware('auth:devotee')
            ->name('logout');
    });

    Route::middleware('auth:devotee')->group(function (): void {
        Route::get('me', [DevoteeProfileController::class, 'show'])->name('me.show');
        Route::patch('me', [DevoteeProfileController::class, 'update'])->name('me.update');

        Route::get('me/saved-temples', [DevoteeProfileController::class, 'savedTemples'])->name('me.saved.index');
        Route::put('me/saved-temples/{temple:slug}', [DevoteeProfileController::class, 'saveTemple'])->name('me.saved.store');
        Route::delete('me/saved-temples/{temple:slug}', [DevoteeProfileController::class, 'forgetTemple'])->name('me.saved.destroy');
    });
});

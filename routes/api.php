<?php

use App\Http\Controllers\Api\V1\AppConfigController;
use App\Http\Controllers\Api\V1\Auth\DevoteeAuthController;
use App\Http\Controllers\Api\V1\Auth\SocialAuthController;
use App\Http\Controllers\Api\V1\NotificationController;
use App\Http\Controllers\Api\V1\SubscriptionController;
use App\Http\Controllers\Api\V1\DeityController;
use App\Http\Controllers\Api\V1\DevoteeProfileController;
use App\Http\Controllers\Api\V1\DevotionalDayController;
use App\Http\Controllers\Api\V1\EventController;
use App\Http\Controllers\Api\V1\FacilityController;
use App\Http\Controllers\Api\V1\LocaleController;
use App\Http\Controllers\Api\V1\MemoryController;
use App\Http\Controllers\Api\V1\PassportController;
use App\Http\Controllers\Api\V1\PincodeController;
use App\Http\Controllers\Api\V1\PassportShareController;
use App\Http\Controllers\Api\V1\SevaDriveController;
use App\Http\Controllers\Api\V1\StateController;
use App\Http\Controllers\Api\V1\SupportController;
use App\Http\Controllers\Api\V1\TempleCategoryController;
use App\Http\Controllers\Api\V1\TempleController;
use App\Http\Controllers\Api\V1\TempleQrController;
use App\Http\Controllers\Api\V1\VisitPhotoController;
use App\Http\Controllers\Api\V1\YatraController;
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

    // Which languages the app may offer, and which it ships with. Served so
    // adding Kannada does not require shipping a new build to enable it.
    Route::get('languages', [LocaleController::class, 'index'])->name('languages.index');

    /*
    |--------------------------------------------------------------------------
    | Support and reports
    |--------------------------------------------------------------------------
    |
    | Filing is deliberately open to anyone, signed in or not. A report that
    | needs an account is a report most people will not file, and the listing
    | with the wrong timings goes on sending devotees to a closed gate.
    |
    | Throttled harder than the read endpoints: each one costs a person's
    | attention rather than a query.
    |
    */
    Route::get('support/options', [SupportController::class, 'options'])->name('support.options');
    Route::post('support', [SupportController::class, 'store'])
        ->middleware('throttle:10,1')
        ->name('support.store');

    // Whether a scanned temple code is one we issued. Open, like search: the
    // app checks a code before it offers to stamp anything.
    Route::post('qr/verify', [TempleQrController::class, 'verify'])
        ->middleware('throttle:60,1')
        ->name('qr.verify');

    // Someone else's passport, from the code they showed. The code is a
    // random token; throttled so it cannot be guessed at speed either.
    Route::get('passports/{code}', [PassportShareController::class, 'show'])
        ->where('code', '[A-Za-z0-9]{16,32}')
        ->middleware('throttle:60,1')
        ->name('passports.show');

    Route::get('events', [EventController::class, 'index'])->name('events.index');

    /*
    |--------------------------------------------------------------------------
    | Seva drives
    |--------------------------------------------------------------------------
    |
    | Devotees organising the care of old temples and heritage places. Reading
    | is open, like the temple listing; only approved drives are ever listed,
    | and a drive's UPI ID is served only once staff have verified the work.
    |
    */
    // Typing a PIN code fills in the state, district and town. Throttled: it
    // is answered by a third party on a cache miss.
    Route::get('pincode/{pincode}', [PincodeController::class, 'show'])
        ->where('pincode', '[0-9]{6}')
        ->middleware('throttle:30,1')
        ->name('pincode.show');

    Route::get('seva-drives/options', [SevaDriveController::class, 'options'])->name('seva.options');
    Route::get('seva-drives', [SevaDriveController::class, 'index'])->name('seva.index');
    Route::get('seva-drives/{drive}', [SevaDriveController::class, 'show'])
        ->whereNumber('drive')
        ->name('seva.show');

    /*
    |--------------------------------------------------------------------------
    | App control, notifications and plans
    |--------------------------------------------------------------------------
    |
    | Read on every launch and open to everyone: maintenance, updates, sign-in
    | methods, ads, payments and push setup, all set from the admin panel.
    | The inbox and device registration work signed out too.
    |
    */
    Route::get('app/config', [AppConfigController::class, 'show'])->name('app.config');
    Route::get('notifications', [NotificationController::class, 'index'])->name('notifications.index');
    Route::post('devices', [NotificationController::class, 'registerDevice'])->middleware('throttle:20,1')->name('devices.store');
    Route::post('devices/forget', [NotificationController::class, 'forgetDevice'])->middleware('throttle:20,1')->name('devices.destroy');
    Route::get('plans', [SubscriptionController::class, 'plans'])->name('plans.index');

    // Gateways call these server to server. Each one is verified by its
    // signature or by asking the gateway, never by trusting the body.
    Route::post('payments/webhook/{gateway}', [SubscriptionController::class, 'webhook'])
        ->where('gateway', '[a-z]+')
        ->middleware('throttle:120,1')
        ->name('payments.webhook');

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

        // "Continue with Google" / "Sign in with Apple": the identity token the
        // app obtained on the device, verified here.
        Route::post('google', [SocialAuthController::class, 'google'])
            ->middleware('throttle:10,1')
            ->name('google');
        Route::post('apple', [SocialAuthController::class, 'apple'])
            ->middleware('throttle:10,1')
            ->name('apple');

        Route::post('logout', [DevoteeAuthController::class, 'logout'])
            ->middleware('auth:devotee')
            ->name('logout');
    });

    Route::middleware('auth:devotee')->group(function (): void {
        Route::get('me', [DevoteeProfileController::class, 'show'])->name('me.show');
        Route::patch('me', [DevoteeProfileController::class, 'update'])->name('me.update');

        // Multipart, so it cannot ride on the JSON PATCH above.
        Route::post('me/avatar', [DevoteeProfileController::class, 'storeAvatar'])->name('me.avatar.store');
        Route::delete('me/avatar', [DevoteeProfileController::class, 'destroyAvatar'])->name('me.avatar.destroy');

        Route::get('me/saved-temples', [DevoteeProfileController::class, 'savedTemples'])->name('me.saved.index');
        Route::put('me/saved-temples/{temple:slug}', [DevoteeProfileController::class, 'saveTemple'])->name('me.saved.store');
        Route::delete('me/saved-temples/{temple:slug}', [DevoteeProfileController::class, 'forgetTemple'])->name('me.saved.destroy');

        /*
        |----------------------------------------------------------------------
        | Passport
        |----------------------------------------------------------------------
        |
        | A visit is recorded against a temple, so it is created under the
        | temple's own URL. Everything read back is scoped to the signed-in
        | devotee in the controller queries, never by a caller-supplied id.
        |
        */
        Route::get('me/passport', [PassportController::class, 'show'])->name('me.passport');
        Route::get('me/visits', [PassportController::class, 'index'])->name('me.visits.index');
        Route::get('me/passport/qr', [PassportShareController::class, 'mine'])->name('me.passport.qr');

        Route::post('me/notifications/{notification}/read', [NotificationController::class, 'markRead'])->name('me.notifications.read');
        Route::post('me/notifications/read-all', [NotificationController::class, 'markAllRead'])->name('me.notifications.read_all');

        Route::get('me/subscription', [SubscriptionController::class, 'show'])->name('me.subscription');
        Route::post('me/checkout', [SubscriptionController::class, 'checkout'])->middleware('throttle:10,1')->name('me.checkout');
        Route::get('me/payments/{uuid}', [SubscriptionController::class, 'status'])->name('me.payments.show');
        Route::post('me/payments/{uuid}/confirm', [SubscriptionController::class, 'confirm'])->middleware('throttle:20,1')->name('me.payments.confirm');
        Route::post('me/passport/qr/reset', [PassportShareController::class, 'reset'])
            ->middleware('throttle:6,1')
            ->name('me.passport.qr.reset');
        Route::post('temples/{temple:slug}/visits', [PassportController::class, 'store'])
            ->name('me.visits.store');
        Route::delete('me/visits/{visit}', [PassportController::class, 'destroy'])->name('me.visits.destroy');

        // Photo Stamp. Uploads are throttled harder than the rest: each one
        // costs storage and a moderator's attention, not just a query.
        Route::get('me/photos', [VisitPhotoController::class, 'index'])->name('me.photos.index');
        Route::post('temples/{temple:slug}/photos', [VisitPhotoController::class, 'store'])
            ->middleware('throttle:20,1')
            ->name('me.photos.store');
        Route::delete('me/photos/{photo}', [VisitPhotoController::class, 'destroy'])->name('me.photos.destroy');

        // Memories: the devotee's own writing. Private by default.
        Route::get('me/memories', [MemoryController::class, 'index'])->name('me.memories.index');
        Route::post('me/memories', [MemoryController::class, 'store'])->name('me.memories.store');
        Route::patch('me/memories/{memory}', [MemoryController::class, 'update'])->name('me.memories.update');
        Route::delete('me/memories/{memory}', [MemoryController::class, 'destroy'])->name('me.memories.destroy');

        // Yatra planner.
        // A devotee's own tickets, with the replies and never the notes.
        Route::get('me/support', [SupportController::class, 'index'])->name('me.support.index');
        Route::get('me/support/{reference}', [SupportController::class, 'show'])->name('me.support.show');
        Route::post('me/support/{reference}/replies', [SupportController::class, 'reply'])
            ->middleware('throttle:20,1')
            ->name('me.support.reply');

        /*
        |----------------------------------------------------------------------
        | Seva drives
        |----------------------------------------------------------------------
        |
        | Raising one costs a moderator's attention and storage, so it is
        | throttled like a photo upload. Everything under me/ is the
        | organiser's, checked against the drive in the controller.
        |
        */
        Route::get('me/seva-drives', [SevaDriveController::class, 'mine'])->name('me.seva.index');
        Route::post('me/seva-drives', [SevaDriveController::class, 'store'])
            ->middleware('throttle:6,1')
            ->name('me.seva.store');
        Route::patch('me/seva-drives/{drive}', [SevaDriveController::class, 'update'])->name('me.seva.update');
        Route::post('me/seva-drives/{drive}/media', [SevaDriveController::class, 'addMedia'])
            ->middleware('throttle:20,1')
            ->name('me.seva.media.store');
        Route::delete('me/seva-drives/{drive}/media/{media}', [SevaDriveController::class, 'destroyMedia'])->name('me.seva.media.destroy');
        Route::post('me/seva-drives/{drive}/complete', [SevaDriveController::class, 'complete'])->name('me.seva.complete');
        Route::post('me/seva-drives/{drive}/cancel', [SevaDriveController::class, 'cancel'])->name('me.seva.cancel');
        Route::get('me/seva-drives/{drive}/volunteers', [SevaDriveController::class, 'volunteers'])->name('me.seva.volunteers');
        Route::get('me/seva-drives/{drive}/donations', [SevaDriveController::class, 'donations'])->name('me.seva.donations');
        Route::post('me/seva-drives/{drive}/donations/{donation}/confirm', [SevaDriveController::class, 'confirmDonation'])->name('me.seva.donations.confirm');

        Route::post('seva-drives/{drive}/join', [SevaDriveController::class, 'join'])
            ->middleware('throttle:20,1')
            ->name('seva.join');
        Route::delete('seva-drives/{drive}/join', [SevaDriveController::class, 'leave'])->name('seva.leave');
        Route::post('seva-drives/{drive}/donations', [SevaDriveController::class, 'donate'])
            ->middleware('throttle:10,1')
            ->name('seva.donate');

        Route::get('me/yatras', [YatraController::class, 'index'])->name('me.yatras.index');
        Route::post('me/yatras', [YatraController::class, 'store'])->name('me.yatras.store');
        Route::get('me/yatras/{yatra}', [YatraController::class, 'show'])->name('me.yatras.show');
        Route::patch('me/yatras/{yatra}', [YatraController::class, 'update'])->name('me.yatras.update');
        Route::delete('me/yatras/{yatra}', [YatraController::class, 'destroy'])->name('me.yatras.destroy');
        /*
         * withoutScopedBindings, because a temple is not a child of a trip.
         * A nested parameter with a custom key makes Laravel scope the second
         * binding to the first — it would look for $yatra->temples() and fail
         * — but here the trip and the temple are two independent records that
         * this request is about to associate. Ownership of the trip is still
         * checked in the controller.
         */
        Route::put('me/yatras/{yatra}/temples/{temple:slug}', [YatraController::class, 'addStop'])
            ->withoutScopedBindings()
            ->name('me.yatras.stops.store');
        Route::delete('me/yatras/{yatra}/temples/{temple:slug}', [YatraController::class, 'removeStop'])
            ->withoutScopedBindings()
            ->name('me.yatras.stops.destroy');
    });
});

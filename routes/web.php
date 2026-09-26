<?php

use App\Http\Controllers\BookingPageController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\PassportPageController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\TempleQrPrintController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\TempleCheckinController;
use Illuminate\Support\Facades\Route;

Route::get('/', function () {
    return view('welcome');
});

/*
|--------------------------------------------------------------------------
| Locally stored media, as a fallback
|--------------------------------------------------------------------------
|
| In a healthy install this route is never reached: public/storage is a
| symlink, so the web server finds the file and answers before PHP is
| involved. It exists for when that link is missing — some shared hosts
| disable symlink() altogether, and a redeploy that unpacks over the top can
| lose it. The symptom is every uploaded image going blank at once with
| nothing in the logs, because the requests never reached the application.
|
| Declared here rather than through the filesystem config's own `serve`
| option, which Laravel skips whenever routes are cached — which is exactly
| what `app:deploy` does. That version would work locally and be absent in
| production, which is the one combination worth avoiding.
|
| The pattern excludes nothing: `where('path', '.*')` is what lets a nested
| path like temples/12/photo.jpg match a single parameter.
|
*/
/*
|--------------------------------------------------------------------------
| Installing the panels on a phone
|--------------------------------------------------------------------------
|
| The worker is at the site root deliberately: a service worker may only
| control pages at or below its own path, so one served from /pwa/sw.js could
| never control /admin. The manifests can live anywhere, since a manifest's
| scope is not bounded by where the manifest itself is served from.
|
*/
Route::get('/sw.js', [PwaController::class, 'serviceWorker'])->name('pwa.worker');
Route::get('/offline', [PwaController::class, 'offline'])->name('pwa.offline');
Route::get('/manifest/{panel}.webmanifest', [PwaController::class, 'manifest'])
    ->where('panel', '[a-z-]+')
    ->name('pwa.manifest');

/*
| A temple's check-in code, opened by a phone camera rather than the app.
*/
Route::get('/temples/{slug}/checkin', TempleCheckinController::class)
    ->where('slug', '[a-z0-9-]+')
    ->name('temples.checkin');

/*
| A devotee's passport code, opened by a phone camera rather than the app.
*/
Route::get('/passport/{code}', PassportPageController::class)
    ->where('code', '[A-Za-z0-9]{16,32}')
    ->middleware('throttle:60,1')
    ->name('passport.show');

/*
| A seva booking's code, opened by a phone camera rather than the portal.
*/
Route::get('/bookings/{code}', BookingPageController::class)
    ->where('code', '[A-Za-z0-9]{20,40}')
    ->middleware('throttle:60,1')
    ->name('bookings.show');

/*
| A temple's check-in code as a printable poster. Signed-in panel users only
| (staff, or the temple's own approved admins); the controller decides which,
| and sends a signed-out visitor to sign in first.
*/
Route::get('/qr/temples/{temple}/print', [TempleQrPrintController::class, 'show'])->name('temples.qr.print');
Route::get('/qr/temples/{temple}/download', [TempleQrPrintController::class, 'download'])->name('temples.qr.download');

/*
| Checkout, opened in the app's in-app browser. The start page is a signed,
| short-lived link from POST /api/v1/me/checkout; the return is where each
| gateway sends the devotee back (GET or POST, depending on the gateway).
*/
Route::get('/pay/{payment}', [PayController::class, 'show'])->middleware('signed')->name('pay.show');
Route::match(['get', 'post'], '/pay/{payment}/return/{gateway}', [PayController::class, 'return'])
    ->where('gateway', '[a-z]+')
    ->middleware('throttle:30,1')
    ->name('pay.return');
Route::get('/pay/{payment}/done', [PayController::class, 'done'])->name('pay.done');

Route::get('/storage/{path}', MediaFileController::class)
    ->where('path', '.*')
    ->name('media.file');

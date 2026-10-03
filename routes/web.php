<?php

use App\Enums\TempleStatus;
use App\Http\Controllers\BookingPageController;
use App\Http\Controllers\KycDocumentController;
use App\Http\Controllers\MediaFileController;
use App\Http\Controllers\MediaPreviewController;
use App\Http\Controllers\PageController;
use App\Http\Controllers\PassportPageController;
use App\Http\Controllers\PayController;
use App\Http\Controllers\PublicTempleController;
use App\Http\Controllers\PwaController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TempleCheckinController;
use App\Http\Controllers\TempleQrPrintController;
use App\Http\Controllers\WebAppHomeController;
use App\Models\Temple;
use App\Support\AppLinks;
use App\Support\IndexNow;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// temple.darshansaathi.com itself: what this server is, and the way in for
// staff and temples. Devotees are pointed at the website and the app.
Route::get('/', function (Request $request) {
    // darshansaathi.com/: the web app, with the site's verification tags and
    // pasted code (see WebAppHomeController).
    if (Seo::onWebsite($request)) {
        return app(WebAppHomeController::class)();
    }

    return view('home', [
        'temples' => Temple::query()->where('status', TempleStatus::Published)->count(),
    ]);
})->name('home');
Route::get('/index.html', fn (Request $request) => Seo::onWebsite($request)
    ? app(WebAppHomeController::class)()
    : redirect('/'));

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

// Public, indexable pages for the devotees' website: darshansaathi.com's
// .htaccess sends these paths here. See App\Support\Seo.
Route::get('/temples', [PublicTempleController::class, 'index'])->name('site.temples');
Route::get('/temples/{slug}', [PublicTempleController::class, 'show'])->name('site.temple');
Route::get('/states/{slug}', [PublicTempleController::class, 'state'])->name('site.state');
Route::get('/deities/{slug}', [PublicTempleController::class, 'deity'])->name('site.deity');
// Search Console's "HTML file" check: the file named in Admin → Analytics & SEO.
Route::get('/google{token}.html', function (string $token) {
    $file = 'google'.$token.'.html';
    abort_unless(setting('google_site_verification_file') === $file, 404);

    return response('google-site-verification: '.$file, 200, ['Content-Type' => 'text/html; charset=UTF-8']);
})->where('token', '[0-9a-f]+');
// Open temple links in the installed app (see App\Support\AppLinks).
Route::get('/.well-known/assetlinks.json', fn () => response()->json(AppLinks::android())->header('Cache-Control', 'public, max-age=3600'));
Route::get('/.well-known/apple-app-site-association', fn () => response()->json(AppLinks::apple())->header('Cache-Control', 'public, max-age=3600'));
Route::get('/apple-app-site-association', fn () => response()->json(AppLinks::apple()));

// IndexNow's proof that pings come from this site (see App\Support\IndexNow).
Route::get('/{key}.txt', function (string $key) {
    abort_unless(hash_equals(IndexNow::key(), $key), 404);

    return response($key, 200, ['Content-Type' => 'text/plain; charset=utf-8']);
})->where('key', '[0-9a-f]{32}');

Route::get('/sitemap.xml', [SitemapController::class, 'index'])->name('sitemap');
Route::get('/sitemap-pages.xml', [SitemapController::class, 'pages']);
Route::get('/sitemap-temples-{page}.xml', [SitemapController::class, 'temples'])->whereNumber('page');

// A saved upload, read back same-origin for the admin panel's upload fields.
// See MediaPreviewController.
Route::get('/media-preview', MediaPreviewController::class)
    ->middleware('signed:relative')
    ->name('media.preview');

Route::get('/storage/{path}', MediaFileController::class)
    ->where('path', '.*')
    ->name('media.file');

// Policy and information pages (privacy, terms, refunds …), edited in
// Admin → Website → Pages. Last of all, so a page can never take over an
// address that something else answers.
Route::post('/account-deletion', [PageController::class, 'requestDeletion'])
    ->middleware('throttle:5,60')
    ->name('site.account-deletion');
Route::fallback([PageController::class, 'show'])->name('site.page');

// A temple owner's verification document (Aadhaar, temple proof, photo), for
// staff only. See KycDocumentController.
Route::get('/admin-kyc/{account}/{document}', KycDocumentController::class)
    ->middleware(['signed', 'auth'])
    ->whereNumber('account')
    ->name('kyc.document');

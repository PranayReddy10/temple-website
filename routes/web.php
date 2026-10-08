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
use App\Http\Controllers\Site\AccountController as SiteAccount;
use App\Http\Controllers\Site\AuthController as SiteAuth;
use App\Http\Controllers\Site\BookingController as SiteBooking;
use App\Http\Controllers\Site\ReviewController as SiteReview;
use App\Http\Controllers\Site\YatraController as SiteYatra;
use App\Http\Controllers\SiteHomeController;
use App\Http\Controllers\SitemapController;
use App\Http\Controllers\TempleCheckinController;
use App\Http\Controllers\TempleQrPrintController;
use App\Models\Temple;
use App\Support\AppLinks;
use App\Support\IndexNow;
use App\Support\Seo;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;

// temple.darshansaathi.com itself: what this server is, and the way in for
// staff and temples. Devotees are pointed at the website and the app.
Route::get('/', function (Request $request) {
    // darshansaathi.com/: the website's home page (SiteHomeController).
    if (Seo::onWebsite($request)) {
        return app(SiteHomeController::class)($request);
    }

    return view('home', [
        'temples' => Temple::query()->where('status', TempleStatus::Published)->count(),
    ]);
})->name('home');
Route::get('/index.html', fn () => redirect('/', 301));

// darshansaathi.com/robots.txt: the website is open to search engines.
// (temple.darshansaathi.com's own is the static public/robots.txt, which
// the web server answers before this route is reached.)
Route::get('/robots.txt', fn (Request $request) => response(Seo::onWebsite($request)
    ? "User-agent: *\nAllow: /\nDisallow: /temples/*/preview\nDisallow: /temples/*/sevas/*/book\nDisallow: /account\n\nSitemap: ".Seo::url('sitemap.xml')."\n"
    : (string) file_get_contents(public_path('robots.txt')), 200, ['Content-Type' => 'text/plain; charset=utf-8']));

// The website was a Flutter web app until it became these pages. A browser
// that kept that app's service worker asks for it again; this one removes
// itself and reloads its pages, so nobody stays on the old cached app.
Route::get('/flutter_service_worker.js', fn () => response(
    "self.addEventListener('install',function(){self.skipWaiting();});\n"
    ."self.addEventListener('activate',function(e){e.waitUntil(self.registration.unregister()"
    .".then(function(){return self.clients.matchAll({type:'window'});})"
    .".then(function(cs){cs.forEach(function(c){c.navigate(c.url);});}));});\n",
    200,
    ['Content-Type' => 'text/javascript; charset=utf-8', 'Cache-Control' => 'no-cache'],
));

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
// A temple's page before it is published, from the admin's Preview page
// button: a signed link that lasts an hour, never indexed.
Route::get('/temples/{temple}/preview', [PublicTempleController::class, 'preview'])
    ->whereNumber('temple')
    ->middleware('signed')
    ->name('site.temple.preview');
Route::get('/states/{state}/{district}', [PublicTempleController::class, 'district'])->name('site.district');
// The same temple page in the apps' other languages (App\Support\SiteLocale).
Route::get('/{locale}/temples/{slug}', [PublicTempleController::class, 'showLocalized'])
    ->where('locale', 'te|hi|ta|kn')
    ->name('site.temple.localized');
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

/*
|--------------------------------------------------------------------------
| Devotees on the website
|--------------------------------------------------------------------------
|
| Signing in, booking sevas, the online hundi, event tickets and the
| devotee's own pages, for everyone without the Android app (iPhone users
| above all). The same accounts and services as the app's API. See
| App\Http\Controllers\Site.
|
*/
Route::get('/login', [SiteAuth::class, 'showLogin'])->name('site.login');
Route::post('/login', [SiteAuth::class, 'login'])->middleware('throttle:10,1');
// Posted by Google's button from accounts.google.com (see SiteAuth::google).
Route::post('/login/google', [SiteAuth::class, 'google'])->middleware('throttle:10,1')->name('site.login.google');
Route::get('/register', [SiteAuth::class, 'showRegister'])->name('site.register');
Route::post('/register', [SiteAuth::class, 'register'])->middleware('throttle:6,1');
Route::post('/logout', [SiteAuth::class, 'logout'])->name('site.logout');
Route::get('/forgot-password', [SiteAuth::class, 'showForgot'])->name('site.password.forgot');
Route::post('/forgot-password', [SiteAuth::class, 'forgot'])->middleware('throttle:3,1');
Route::get('/reset-password', [SiteAuth::class, 'showReset'])->name('site.password.reset');
Route::post('/reset-password', [SiteAuth::class, 'reset'])->middleware('throttle:10,1');

// What a temple offers and its hundi: public pages, for search engines too.
Route::get('/temples/{slug}/sevas', [SiteBooking::class, 'sevas'])->where('slug', '[a-z0-9-]+')->name('site.sevas');
Route::get('/temples/{slug}/sevas/{puja}/slots', [SiteBooking::class, 'slots'])->where('slug', '[a-z0-9-]+')->whereNumber('puja')->middleware('throttle:60,1');
Route::get('/temples/{slug}/donate', [SiteBooking::class, 'donate'])->where('slug', '[a-z0-9-]+')->name('site.donate');

Route::middleware('devotee.web')->group(function (): void {
    Route::get('/temples/{slug}/sevas/{puja}/book', [SiteBooking::class, 'book'])->where('slug', '[a-z0-9-]+')->whereNumber('puja')->name('site.book');
    Route::post('/temples/{slug}/sevas/{puja}/book', [SiteBooking::class, 'storeBooking'])->where('slug', '[a-z0-9-]+')->whereNumber('puja')->middleware('throttle:20,1');
    Route::post('/temples/{slug}/donate', [SiteBooking::class, 'storeDonation'])->where('slug', '[a-z0-9-]+')->middleware('throttle:20,1');
    Route::post('/temples/{slug}/save', [SiteAccount::class, 'toggleSaved'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('site.save');
    Route::post('/events/{event}/join', [SiteBooking::class, 'storeEvent'])->whereNumber('event')->middleware('throttle:20,1')->name('site.event.join');

    Route::get('/account', [SiteAccount::class, 'index'])->name('site.account');
    Route::get('/account/bookings', [SiteAccount::class, 'bookings'])->name('site.account.bookings');
    Route::get('/account/bookings/{reference}', [SiteAccount::class, 'booking'])->where('reference', '[A-Za-z0-9-]+')->name('site.account.booking');
    Route::post('/account/bookings/{reference}/pay', [SiteAccount::class, 'payBooking'])->where('reference', '[A-Za-z0-9-]+')->middleware('throttle:20,1');
    Route::post('/account/bookings/{reference}/cancel', [SiteAccount::class, 'cancelBooking'])->where('reference', '[A-Za-z0-9-]+')->middleware('throttle:20,1');
    Route::get('/account/tickets/{reference}', [SiteAccount::class, 'ticket'])->where('reference', '[A-Za-z0-9-]+')->name('site.account.ticket');
    Route::get('/account/donations', [SiteAccount::class, 'donations'])->name('site.account.donations');
    Route::get('/account/saved', [SiteAccount::class, 'saved'])->name('site.account.saved');
    Route::get('/account/passport', [SiteAccount::class, 'passport'])->name('site.account.passport');
    Route::get('/account/profile', [SiteAccount::class, 'profile'])->name('site.account.profile');
    Route::post('/account/profile', [SiteAccount::class, 'updateProfile']);
    Route::post('/account/password', [SiteAccount::class, 'updatePassword'])->middleware('throttle:10,1');
    Route::post('/account/delete', [SiteAccount::class, 'destroy'])->middleware('throttle:5,1');
    Route::post('/temples/{slug}/reviews', [SiteReview::class, 'store'])->where('slug', '[a-z0-9-]+')->middleware('throttle:10,1')->name('site.review');
    Route::post('/temples/{slug}/reviews/delete', [SiteReview::class, 'destroy'])->where('slug', '[a-z0-9-]+')->middleware('throttle:10,1');
    // The yatra planner (Site\YatraController).
    Route::get('/account/yatras', [SiteYatra::class, 'index'])->name('site.yatras');
    Route::post('/account/yatras', [SiteYatra::class, 'store'])->middleware('throttle:30,1');
    Route::get('/account/yatras/{yatra}', [SiteYatra::class, 'show'])->whereNumber('yatra')->name('site.yatra');
    Route::post('/account/yatras/{yatra}', [SiteYatra::class, 'update'])->whereNumber('yatra');
    Route::post('/account/yatras/{yatra}/delete', [SiteYatra::class, 'destroy'])->whereNumber('yatra');
    Route::post('/account/yatras/{yatra}/optimise', [SiteYatra::class, 'optimise'])->whereNumber('yatra');
    Route::post('/account/yatras/{yatra}/stops', [SiteYatra::class, 'addStop'])->whereNumber('yatra')->middleware('throttle:60,1');
    Route::post('/account/yatras/{yatra}/stops/{stop}/move', [SiteYatra::class, 'moveStop'])->whereNumber(['yatra', 'stop']);
    Route::post('/account/yatras/{yatra}/stops/{stop}/remove', [SiteYatra::class, 'removeStop'])->whereNumber(['yatra', 'stop']);
    Route::post('/temples/{slug}/yatra', [SiteYatra::class, 'addFromTemple'])->where('slug', '[a-z0-9-]+')->middleware('throttle:60,1')->name('site.temple.yatra');
    // Where the checkout pages send a website payment back to.
    Route::get('/account/payments/{uuid}', [SiteBooking::class, 'returned'])->where('uuid', '[0-9a-f-]{36}')->name('site.payment.returned');
});

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

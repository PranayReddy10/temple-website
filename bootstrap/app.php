<?php

use App\Http\Middleware\ActAsTempleTeam;
use App\Http\Middleware\EnsureSuperAdmin;
use App\Http\Middleware\NoStoreApiResponses;
use App\Http\Middleware\SetApiLocale;
use App\Http\Middleware\ThrottlePerRoute;
use App\Support\TrustedProxies;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        apiPrefix: 'api',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The public API is read-only and unauthenticated, so the only thing
        // standing between it and abuse is a rate limit. 60/minute per IP is
        // generous for an app browsing temples and cheap to raise later. It is
        // the named `api` limiter (AppServiceProvider), so its count is its
        // own and browsing never uses up a route's tighter limit.
        $middleware->throttleApi('api');

        // `throttle:N,M` on a route counts that route only; see the class.
        $middleware->alias([
            'throttle' => ThrottlePerRoute::class,
            'temple.team' => ActAsTempleTeam::class,
            'super.admin' => EnsureSuperAdmin::class,
            'devotee.web' => \App\Http\Middleware\DevoteeSignedIn::class,
        ]);

        // Every API response is in some language, so the decision belongs to
        // the whole group rather than to the endpoints that remembered.
        $middleware->api(append: [SetApiLocale::class, NoStoreApiResponses::class]);

        // Gateways POST the devotee back to these pages from their own
        // domain (PayU always, Razorpay's handler form too), which a CSRF
        // token cannot survive. Each return is checked with the gateway
        // itself instead. Google's sign-in button posts to login/google
        // from accounts.google.com too; Google's own g_csrf_token cookie
        // is checked there instead (Site\AuthController::google).
        $middleware->validateCsrfTokens(except: ['pay/*', 'login/google']);

        /*
         * Behind Cloudflare or any TLS-terminating proxy, the origin sees a
         * plain HTTP request carrying X-Forwarded-Proto: https. Untrusted,
         * Laravel believes the scheme is http and generates http:// links and
         * redirects, which breaks the admin panel behind an https domain.
         *
         * Off by default: trusting a forwarded header lets anyone who can reach
         * the origin directly spoof the client IP and scheme. Only enable it
         * when the app really does sit behind a proxy. Behind Cloudflare, where
         * the origin address is not publicly advertised, TRUSTED_PROXIES=*
         * is the usual setting.
         */
        if ($proxies = TrustedProxies::from(env('TRUSTED_PROXIES'))) {
            // Client address, scheme and port only. Never X-Forwarded-Host:
            // links the app emails (staff password resets) are built from
            // the request's host, and a forwarded host is whatever the
            // sender typed. Cloudflare keeps the real Host header anyway.
            $middleware->trustProxies(at: $proxies, headers: TrustedProxies::HEADERS);
        }

        // Only our own addresses (the admin host, the website and their
        // subdomains) are answered; a request naming another host cannot
        // make the app build links to it.
        $middleware->trustHosts(at: fn (): array => TrustedProxies::hosts());
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

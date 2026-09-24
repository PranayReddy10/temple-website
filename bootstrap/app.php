<?php

use App\Http\Middleware\SetApiLocale;
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
        // generous for an app browsing temples and cheap to raise later.
        $middleware->throttleApi('60,1');

        // Every API response is in some language, so the decision belongs to
        // the whole group rather than to the endpoints that remembered.
        $middleware->api(append: [SetApiLocale::class]);

        // Gateways POST the devotee back to these pages from their own
        // domain (PayU always, Razorpay's handler form too), which a CSRF
        // token cannot survive. Each return is checked with the gateway
        // itself instead.
        $middleware->validateCsrfTokens(except: ['pay/*']);

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
            $middleware->trustProxies(at: $proxies);
        }
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        //
    })->create();

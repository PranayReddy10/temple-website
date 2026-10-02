<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * API answers are never to be kept by a proxy or CDN.
 *
 * Behind Cloudflare (or a host cache such as LiteSpeed), a "cache
 * everything" rule otherwise keeps a GET's answer and hands it back after
 * the data changed: a temple team saves its address or a seva, the admin
 * panel shows it, and the app keeps showing the old copy. Laravel's default
 * `no-cache, private` is not enough for every edge, so this says it in each
 * dialect. The devotee app keeps its own short copy where that is wanted.
 */
class NoStoreApiResponses
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // A file download sets its own caching on purpose.
        if ($response->headers->has('Content-Disposition')) {
            return $response;
        }

        $response->headers->set('Cache-Control', 'no-store, no-cache, private, max-age=0, must-revalidate');
        $response->headers->set('Pragma', 'no-cache');
        $response->headers->set('Expires', '0');
        $response->headers->set('CDN-Cache-Control', 'no-store');
        $response->headers->set('Cloudflare-CDN-Cache-Control', 'no-store');
        $response->headers->set('X-LiteSpeed-Cache-Control', 'no-cache');

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use App\Support\Locales;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Decides once, per request, which language the API is answering in.
 *
 * Doing it here rather than in each controller is what keeps `?lang=te`
 * meaning the same thing on every endpoint, and stops an unrecognised value
 * from reaching a query as a table or column name.
 *
 * The chosen language goes back in a response header. Without it a caching
 * layer in front of this — Cloudflare, in our case — would serve the Telugu
 * response to the next devotee who asked for Tamil, and Vary is how it is
 * told these are different documents.
 */
class SetApiLocale
{
    public function handle(Request $request, Closure $next): Response
    {
        $locale = Locales::forRequest($request);

        app()->setLocale($locale);

        $response = $next($request);

        $response->headers->set('Content-Language', $locale);
        $response->headers->set('Vary', 'Accept-Language', false);

        return $response;
    }
}

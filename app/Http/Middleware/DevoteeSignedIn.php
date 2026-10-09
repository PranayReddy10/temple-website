<?php

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A website page for a signed-in devotee: anyone else is sent to sign in
 * first, and comes back to this page afterwards.
 */
class DevoteeSignedIn
{
    public function handle(Request $request, Closure $next): Response
    {
        if (! Auth::guard('devotee_web')->check()) {
            if ($request->isMethod('GET')) {
                $request->session()->put('url.intended', $request->fullUrl());
            }

            return redirect()->to(Seo::url('login'));
        }

        // The devotee for $request->user() and policies, as on the API.
        Auth::shouldUse('devotee_web');

        return $next($request);
    }
}

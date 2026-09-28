<?php

namespace App\Http\Middleware;

use App\Models\User;
use App\Support\ActingStaff;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Admits a temple team's token to the trust app API, and makes the rest of
 * the application see who is acting.
 *
 * Runs after auth:trust. Two things happen here:
 *
 * 1. Only an active temple admin passes. Staff tokens are never issued by
 *    this API, but a role changed after sign-in must still stop at the door.
 *
 * 2. The same user is placed on the staff guard for this request. The
 *    observers read ActingStaff, which asks the staff guard by name; without
 *    this, a temple team writing through the API would look like "nobody
 *    signed in" to the event observer — the case trusted like a seeder — and
 *    its events would publish without review. With it, the API lands on
 *    exactly the answers the temple portal gives.
 */
class ActAsTempleTeam
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('trust')->user();

        if (! $user instanceof User) {
            abort(401, 'Sign in again.');
        }

        if (! (bool) $user->is_active) {
            abort(403, 'This account is no longer active.');
        }

        if (! $user->isTempleAdmin()) {
            abort(403, 'The trust app is for temple teams. Staff accounts sign in to the admin panel.');
        }

        Auth::guard(ActingStaff::GUARD)->setUser($user);

        return $next($request);
    }
}

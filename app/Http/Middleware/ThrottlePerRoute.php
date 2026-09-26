<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Routing\Middleware\ThrottleRequests;

/**
 * `throttle:N,M` with a counter of its own for each route.
 *
 * Laravel's stock middleware keys every numeric limit on the client alone, so
 * `throttle:6,1` on sign-in shared its counter with the API-wide limit and
 * every other throttled route: a minute of browsing temples used up the six
 * sign-in attempts before the first one was made. Here the route and the limit
 * are part of the key, so each limit only counts the requests it guards.
 */
class ThrottlePerRoute extends ThrottleRequests
{
    public function handle($request, Closure $next, $maxAttempts = 60, $decayMinutes = 1, $prefix = '')
    {
        // A named limiter (`throttle:api`) already keys by its own name.
        if (is_string($maxAttempts) && func_num_args() === 3 && $this->limiter->limiter($maxAttempts) !== null) {
            return parent::handle($request, $next, $maxAttempts);
        }

        $route = $request->route();
        $scope = $route ? implode('|', $route->methods()).' '.$route->uri() : $request->method().' '.$request->path();

        return parent::handle($request, $next, $maxAttempts, $decayMinutes, $prefix.sha1($scope.'|'.$maxAttempts.'|'.$decayMinutes).'|');
    }
}

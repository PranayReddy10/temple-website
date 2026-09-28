<?php

namespace App\Http\Middleware;

use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/** The trust app's admin routes: super admins only, after temple.team. */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        $user = Auth::guard('trust')->user();

        abort_unless($user instanceof User && $user->isSuperAdmin(), 403, 'Only a super admin can do this.');

        return $next($request);
    }
}

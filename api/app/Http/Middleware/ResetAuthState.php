<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * The application stays in memory between requests (Octane, architecture.md section 4.2),
 * and two things of the framework remember what the last request left: the guard keeps the
 * user it found, and the session store keeps the values it held (loading a session merges
 * into them). Forget both at the start of every request that uses the session, so the user
 * and the session of one request are never those of the next. The sessions themselves stay
 * in their handler (Redis).
 */
class ResetAuthState
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        Auth::forgetGuards();
        app('session.store')->flush();

        return $next($request);
    }
}

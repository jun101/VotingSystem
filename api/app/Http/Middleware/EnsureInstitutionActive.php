<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user whose institution was suspended after they signed in is refused (403
 * `institution_suspended`), and their session is ended (docs/api/auth/GET-auth-me.md).
 */
class EnsureInstitutionActive
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->institution?->isSuspended()) {
            Auth::guard()->logout();
            $request->session()->invalidate();
            $request->session()->regenerateToken();

            throw new ApiException(403, 'institution_suspended');
        }

        return $next($request);
    }
}

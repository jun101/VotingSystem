<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A user who must change a one-time password (school mode) can do nothing else: only who they are, signing out, the CSRF
 * token and the change itself. Everything else answers 403 `password_change_required`. A platform admin acting as that
 * user is not held by it.
 */
final class EnforcePasswordChange
{
    /** The paths a user in that state may reach (after the `api/v1/` prefix). */
    private const ALLOWED = ['auth/me', 'auth/logout', 'auth/csrf', 'auth/change-password'];

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if ($user instanceof User && $user->must_change_password === true && Impersonation::by($request) === null) {
            foreach (self::ALLOWED as $path) {
                if ($request->is('api/v1/'.$path)) {
                    return $next($request);
                }
            }

            throw new ApiException(403, 'password_change_required');
        }

        return $next($request);
    }
}

<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Ends an acting-as session (slice 18c, security review 2026-10-12) when the admin behind it is gone, is no longer a
 * platform admin, or has changed their password since the start (the fingerprint stored at the start no longer
 * matches): the session is flushed and the request answers 401. A session that is not acting costs nothing.
 */
class EndStaleImpersonation
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();
        $id = $session->get(Impersonation::KEY_ID);

        if (! is_int($id)) {
            return $next($request);
        }

        $fingerprint = $session->get(Impersonation::KEY_FINGERPRINT);

        // The admin has no institution, so the tenant scope would match nobody: the row is read by its key.
        $admin = User::withoutInstitutionScope()->whereKey($id)->where('role', Role::PlatformAdmin->value)->first();

        if ($admin === null || ! is_string($fingerprint) || ! hash_equals($fingerprint, Impersonation::fingerprint($admin))) {
            Auth::guard()->logout();
            $session->invalidate();
            $session->regenerateToken();

            throw new ApiException(401, 'unauthenticated');
        }

        return $next($request);
    }
}

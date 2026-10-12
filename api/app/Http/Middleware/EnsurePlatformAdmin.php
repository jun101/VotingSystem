<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\Platform\Impersonation;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only a platform admin passes (`platform.admin` on a route, behind `auth`): an owner or a manager is
 * refused with 403 `forbidden`. It runs before the rate limit (bootstrap/app.php), so a refused request does
 * not use up the admin's allowance and the role is judged before anything about the institution is known.
 */
class EnsurePlatformAdmin
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string ...$options): Response
    {
        $user = $request->user();

        // `acting`: the route is also answered to an admin acting as an owner (the session user is then the owner), so
        // that starting a second impersonation is a 409 and not a 403.
        if (in_array('acting', $options, true) && Impersonation::isActing($request)) {
            return $next($request);
        }

        if (! $user instanceof User || $user->role !== Role::PlatformAdmin) {
            throw new ApiException(403, 'forbidden');
        }

        return $next($request);
    }
}

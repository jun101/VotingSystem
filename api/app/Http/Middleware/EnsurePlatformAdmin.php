<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
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
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== Role::PlatformAdmin) {
            throw new ApiException(403, 'forbidden');
        }

        return $next($request);
    }
}

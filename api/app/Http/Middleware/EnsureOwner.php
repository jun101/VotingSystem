<?php

namespace App\Http\Middleware;

use App\Enums\Role;
use App\Exceptions\ApiException;
use App\Models\User;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Only an owner of the institution passes (`owner` on a route): a manager is refused with 403
 * `forbidden`. It runs after the route's records are bound (bootstrap/app.php), so a record of
 * another institution answers 404 first and is never described to a manager.
 */
class EnsureOwner
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user instanceof User || $user->role !== Role::Owner) {
            throw new ApiException(403, 'forbidden');
        }

        return $next($request);
    }
}

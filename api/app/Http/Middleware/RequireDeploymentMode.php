<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use App\Support\Deployment;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * `deployment:school` or `deployment:online`: the route exists in that mode only, and answers 404 in the other, or 405 with
 * `deployment:school,method` (docs/api/README.md section 7a). The mode is read from the config at each request.
 */
final class RequireDeploymentMode
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next, string $mode, string $otherwise = 'not_found'): Response
    {
        if (Deployment::mode() !== $mode) {
            // `method`: the route shares its path with another that exists in every mode (POST /users beside GET /users),
            // so in the other mode that method is simply not allowed, as it was before the route existed.
            if ($otherwise === 'method') {
                throw new MethodNotAllowedHttpException(['GET', 'HEAD']);
            }

            throw new ApiException(404, 'not_found');
        }

        return $next($request);
    }
}

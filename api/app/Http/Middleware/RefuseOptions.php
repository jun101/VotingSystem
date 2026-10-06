<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\MethodNotAllowedHttpException;

/**
 * `OPTIONS` is a method like any other: an endpoint that does not list it answers 405
 * with the error shape (docs/api/system/GET-health.md, scenario 4).
 *
 * The router answers any `OPTIONS` on a known path with an empty 200 of its own, which
 * is neither JSON nor in the conventions. This middleware turns that answer into the
 * 405, keeping the router's list of accepted methods for the `Allow` header. The
 * application is same-origin and allows no cross-origin request (config/cors.php), so
 * there is no preflight to answer.
 */
class RefuseOptions
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $allow = $response->headers->get('Allow');

        if ($request->isMethod('OPTIONS') && $response->isSuccessful() && $allow !== null) {
            throw new MethodNotAllowedHttpException(array_map(trim(...), explode(',', $allow)));
        }

        return $response;
    }
}

<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/**
 * The voting routes use no CSRF token (the voter has no page session, only a `SameSite=Strict` cookie):
 * what keeps another site from posting for a voter is that the body must be JSON. A form post from
 * another origin cannot be `application/json` without a preflight the API never allows. A POST
 * whose body is not declared JSON answers 400 `malformed_request`.
 */
class RequireJsonBody
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if ($request->isMethod('POST') && ! $request->isJson()) {
            throw new BadRequestHttpException;
        }

        return $next($request);
    }
}

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
        if ($request->isMethod('POST')) {
            if (! $this->isJson($request)) {
                throw new BadRequestHttpException;
            }

            // The framework reads the body as JSON only when it finds `/json` in lower case.
            $request->headers->set('Content-Type', strtolower((string) $request->headers->get('Content-Type')));
        }

        return $next($request);
    }

    /** The media type is `application/json` exactly (parameters such as charset ignored; no `+json` types). */
    private function isJson(Request $request): bool
    {
        $type = strtolower(trim(explode(';', (string) $request->headers->get('Content-Type'), 2)[0]));

        return $type === 'application/json';
    }
}

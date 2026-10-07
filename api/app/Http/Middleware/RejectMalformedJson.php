<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpKernel\Exception\BadRequestHttpException;

/** A JSON body that cannot be read answers 400 `malformed_request`, not an empty form. */
class RejectMalformedJson
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $body = $request->getContent();

        if ($request->isJson() && $body !== '') {
            $decoded = json_validate($body) ? json_decode($body, true) : null;

            if (! is_array($decoded)) {
                throw new BadRequestHttpException;
            }
        }

        return $next($request);
    }
}

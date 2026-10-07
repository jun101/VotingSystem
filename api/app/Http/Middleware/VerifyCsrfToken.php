<?php

namespace App\Http\Middleware;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

/**
 * The framework's CSRF protection (a token tied to the session, copied by the page from the
 * `XSRF-TOKEN` cookie into the `X-XSRF-TOKEN` header), with three differences:
 *
 * - it is also enforced when the application runs its tests (the framework skips it then),
 *   so the 419 answers are tested;
 * - the `X-XSRF-TOKEN` header is the only proof. The framework also accepts a `_token` field,
 *   an `X-CSRF-TOKEN` header, and a request whose `Sec-Fetch-Site` header says
 *   "same-origin" without a token;
 * - the cookie holds the token as it is, not encrypted: it is a random value tied to the
 *   session, and the page copies it as it reads it. Nothing else in this API uses a cookie
 *   that would need the framework's cookie encryption.
 *
 * See docs/api/auth/GET-auth-csrf.md.
 */
class VerifyCsrfToken extends PreventRequestForgery
{
    protected function runningUnitTests(): bool
    {
        return false;
    }

    protected function hasValidOrigin($request): bool
    {
        return false;
    }

    protected function getTokenFromRequest($request): ?string
    {
        $header = $request->header('X-XSRF-TOKEN');

        return is_string($header) ? $header : null;
    }
}

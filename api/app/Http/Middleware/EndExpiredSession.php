<?php

namespace App\Http\Middleware;

use App\Exceptions\ApiException;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Config;
use Symfony\Component\HttpFoundation\Response;

/**
 * The absolute session lifetime (slice 19, ASVS 3.3.2): a signed-in session ends `session.absolute_lifetime`
 * minutes after the sign-in, however active it is. The sign-in time is kept in the session (`STARTED_AT`, set by
 * `start()` at every sign-in); when it is past, the session is flushed and the request answers 401
 * `unauthenticated`. Nothing is kept between requests (Octane): the session and the config are read each time.
 */
class EndExpiredSession
{
    public const STARTED_AT = 'session_started_at';

    /** Records the sign-in time in the session of the request. Impersonation does not call it: the admin's time stays. */
    public static function start(?Request $request = null): void
    {
        $request ??= request();

        if ($request->hasSession()) {
            $request->session()->put(self::STARTED_AT, now()->getTimestamp());
        }
    }

    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        if (! $request->hasSession()) {
            return $next($request);
        }

        $session = $request->session();

        // Only a signed-in session has a lifetime: a pending sign-in has its own five minutes.
        if (! $session->has(Auth::guard()->getName())) {
            return $next($request);
        }

        $started = $session->get(self::STARTED_AT);

        if (! is_int($started)) {
            // A session older than this rule: its life starts now.
            $session->put(self::STARTED_AT, now()->getTimestamp());

            return $next($request);
        }

        $limit = Config::integer('session.absolute_lifetime');

        if ($limit > 0 && now()->getTimestamp() - $started >= $limit * 60) {
            Auth::guard()->logout();
            $session->invalidate();
            $session->regenerateToken();

            throw new ApiException(401, 'unauthenticated');
        }

        return $next($request);
    }
}

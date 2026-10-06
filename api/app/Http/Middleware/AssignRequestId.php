<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Log;
use Ramsey\Uuid\Uuid;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gives every request a random version 4 UUID, returned in `X-Request-Id` and added to
 * every log line of the request. A value sent by the client is ignored: the id is ours.
 *
 * Registered as a global middleware, so it also covers routes outside the `api` group.
 */
class AssignRequestId
{
    public const HEADER = 'X-Request-Id';

    public const ATTRIBUTE = 'request_id';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $id = Uuid::uuid4()->toString();

        // Octane keeps the application in memory between requests: start from a clean
        // state so nothing of the previous request (log context, language) carries over.
        // (setLocale also rewrites config('app.locale'), so the default is read from the
        // fallback locale, which it never touches.)
        Log::flushSharedContext();
        app()->setLocale(Config::string('app.fallback_locale'));

        $request->attributes->set(self::ATTRIBUTE, $id);
        Log::shareContext(['request_id' => $id]);

        $response = $next($request);
        $response->headers->set(self::HEADER, $id);

        return $response;
    }

    /** The id of the request being handled, or null outside a request. */
    public static function of(Request $request): ?string
    {
        $id = $request->attributes->get(self::ATTRIBUTE);

        return is_string($id) ? $id : null;
    }
}

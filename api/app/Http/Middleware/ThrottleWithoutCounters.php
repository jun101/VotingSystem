<?php

namespace App\Http\Middleware;

use Illuminate\Routing\Middleware\ThrottleRequests;
use Symfony\Component\HttpFoundation\Response;

/**
 * The named rate limiter (`throttle:name`) without the `X-RateLimit-*` counters on answers
 * that went through. Used where an endpoint file says that several causes give "the same body
 * and headers" (accept-invitation, scenario 6): a counter that goes down with each request
 * would tell two answers apart. A refused request still carries `Retry-After`.
 */
class ThrottleWithoutCounters extends ThrottleRequests
{
    /**
     * @param  int  $maxAttempts
     * @param  int  $remainingAttempts
     * @param  int|null  $retryAfter
     */
    protected function addHeaders(Response $response, $maxAttempts, $remainingAttempts, $retryAfter = null): Response
    {
        return $response;
    }
}

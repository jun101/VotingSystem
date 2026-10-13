<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;
use Symfony\Component\HttpFoundation\Response;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * The hardening headers of every API answer (slice 19, NFR-SEC-04): no sniffing, no referrer, a policy that allows
 * nothing (an API answer is data, never a page) and no storage by caches. A `Cache-Control` the route set on
 * purpose, and a file download, are left alone. Stateless.
 */
class AddApiSecurityHeaders
{
    /** @param  Closure(Request): Response  $next */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        $response->headers->set('X-Content-Type-Options', 'nosniff');
        $response->headers->set('Referrer-Policy', 'no-referrer');
        $response->headers->set('Content-Security-Policy', "default-src 'none'; frame-ancestors 'none'");

        $download = $response instanceof BinaryFileResponse
            || $response instanceof StreamedResponse
            || str_contains((string) $response->headers->get('Content-Disposition'), 'attachment');

        if (! $download && ! $response->headers->hasCacheControlDirective('no-store')) {
            // Symfony fills a default `no-cache, private` when none was set: only a Cache-Control the route chose is kept.
            if (! $this->setOnPurpose($response)) {
                $response->headers->set('Cache-Control', 'no-cache, no-store, private');
            }
        }

        return $response;
    }

    private function setOnPurpose(Response $response): bool
    {
        return $response->headers->has('Cache-Control')
            && ($response->headers->hasCacheControlDirective('max-age') || $response->headers->hasCacheControlDirective('public'));
    }
}

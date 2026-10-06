<?php

use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\RefuseOptions;
use App\Support\TrustedHosts;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        apiPrefix: 'api',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // The API publishes no port: only the proxy and the web container, on the Compose
        // network (private addresses), reach it. Trust the address and the scheme they
        // forward, and nothing else: not the forwarded host, port or prefix, which would
        // let a visitor shape the absolute links we build.
        $middleware->trustProxies(
            at: ['10.0.0.0/8', '172.16.0.0/12', '192.168.0.0/16'],
            headers: Request::HEADER_X_FORWARDED_FOR | Request::HEADER_X_FORWARDED_PROTO,
        );

        // Outside local development, only the host of APP_URL and the names the Compose
        // network gives the API (see App\Support\TrustedHosts).
        $middleware->trustHosts(at: fn () => TrustedHosts::patterns(), subdomains: false);

        // Global, so that routes outside the `api` group get a request id too.
        $middleware->prepend(AssignRequestId::class);
        $middleware->append(RefuseOptions::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application serves the API only: every answer is JSON, whatever the path
        // and the Accept header (docs/api/README.md section 1).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request));
    })->create();

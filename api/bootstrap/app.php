<?php

use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\AssignRequestId;
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
        // Behind our own proxy only: the API publishes no port, so only the proxy and the
        // web container reach it. The real client address is what the rate limits count.
        $middleware->trustProxies(at: '*');

        // Global, so that routes outside the `api` group get a request id too.
        $middleware->prepend(AssignRequestId::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->shouldRenderJsonWhen(
            fn (Request $request) => $request->is('api/*') || $request->expectsJson(),
        );

        $exceptions->render(fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request));
    })->create();

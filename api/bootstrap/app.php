<?php

use App\Exceptions\ApiErrorRenderer;
use App\Http\Middleware\AssignRequestId;
use App\Http\Middleware\EnsureInstitutionActive;
use App\Http\Middleware\RefuseOptions;
use App\Http\Middleware\RejectMalformedJson;
use App\Http\Middleware\ResetAuthState;
use App\Http\Middleware\VerifyCsrfToken;
use App\Support\TrustedHosts;
use Illuminate\Contracts\Auth\Middleware\AuthenticatesRequests;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\AuthenticateSession;
use Illuminate\Session\Middleware\StartSession;

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

        // The routes that use the cookie session (docs/api/auth/): sign-in, CSRF, the current
        // user. Only they start a session, so `/health` and the public routes set no cookie.
        // The guard forgets its user first (the application stays in memory); a JSON body
        // that cannot be read is refused; the session carries a hash of the password, and
        // is ended when the password changes. The two cookies (session id, CSRF token) are
        // random values and are not encrypted (see VerifyCsrfToken).
        $middleware->group('cookie-session', [
            ResetAuthState::class,
            RejectMalformedJson::class,
            StartSession::class,
            VerifyCsrfToken::class,
            AuthenticateSession::class,
        ]);
        $middleware->alias(['institution.active' => EnsureInstitutionActive::class]);

        // This application serves the API only: a request that is not signed in is answered
        // 401 (see ApiErrorRenderer), never redirected to a sign-in page.
        $middleware->redirectGuestsTo(fn () => null);

        // The framework sorts the middleware of a route by its priority list, and puts those
        // it does not know after the others: say where ours go.
        $middleware->prependToPriorityList(before: StartSession::class, prepend: ResetAuthState::class);
        $middleware->prependToPriorityList(before: StartSession::class, prepend: RejectMalformedJson::class);
        // A signed-out request is answered 401 before its CSRF token is judged: after the
        // session ended, the token the page still holds no longer matches, and that is a
        // sign-in problem, not a forgery.
        $middleware->appendToPriorityList(after: AuthenticatesRequests::class, append: VerifyCsrfToken::class);
        $middleware->appendToPriorityList(after: VerifyCsrfToken::class, append: EnsureInstitutionActive::class);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // This application serves the API only: every answer is JSON, whatever the path
        // and the Accept header (docs/api/README.md section 1).
        $exceptions->shouldRenderJsonWhen(fn () => true);

        $exceptions->render(fn (Throwable $e, Request $request) => app(ApiErrorRenderer::class)($e, $request));
    })->create();

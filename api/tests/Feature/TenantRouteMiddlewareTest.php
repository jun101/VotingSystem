<?php

/*
 * Every route of the tenant lists needs a signed-in user. Those that answer for an
 * institution also pass `institution.active`; sign-out does not, so a suspended
 * institution's user can still leave.
 */

use Illuminate\Support\Facades\Route;
use Tests\Support\Tenancy;

/** @return array<string, list<string>> `METHOD uri` => middleware names */
function routeMiddlewareByLabel(): array
{
    $found = [];
    foreach (Route::getRoutes() as $route) {
        foreach (array_diff($route->methods(), ['HEAD', 'OPTIONS']) as $method) {
            $found[$method.' '.$route->uri()] = $route->gatherMiddleware();
        }
    }

    return $found;
}

/** @return list<string> */
function tenantListedRoutes(): array
{
    return array_merge(Tenancy::OWN_USER_ROUTES, array_keys(Tenancy::TENANT_ROUTES));
}

it('puts auth on every tenant and own-user route [NFR-SEC-03] (rule 5)', function () {
    $routes = routeMiddlewareByLabel();
    $problems = [];

    foreach (tenantListedRoutes() as $label) {
        if (! in_array('auth', $routes[$label] ?? [], true)) {
            $problems[] = "{$label} lacks the auth middleware";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

it('puts institution.active on every tenant and own-user route but sign-out [NFR-SEC-03] (rule 5)', function () {
    $routes = routeMiddlewareByLabel();
    $problems = [];

    foreach (tenantListedRoutes() as $label) {
        if ($label === 'POST api/v1/auth/logout') {
            continue;
        }
        if (! in_array('institution.active', $routes[$label] ?? [], true)) {
            $problems[] = "{$label} lacks the institution.active middleware";
        }
    }

    expect($problems)->toBe([], implode("\n", $problems));
});

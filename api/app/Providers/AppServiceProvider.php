<?php

namespace App\Providers;

use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The OpenAPI file is exported by `make generate`; no documentation page is served.
        Scramble::ignoreDefaultRoutes();
    }

    public function boot(): void
    {
        $this->configureRateLimiters();
    }

    /**
     * Every limiter is declared here by name; a route uses it with `throttle:<name>`.
     * Later slices add theirs the same way.
     */
    private function configureRateLimiters(): void
    {
        RateLimiter::for('health', fn (Request $request) => Limit::perMinute(60)->by((string) $request->ip()));
    }
}

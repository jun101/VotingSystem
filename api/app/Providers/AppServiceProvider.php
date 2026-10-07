<?php

namespace App\Providers;

use App\Models\User;
use Dedoc\Scramble\Scramble;
use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        // The OpenAPI file is exported by `make generate`; no documentation page is served.
        // Scramble is a development package: without it (an install made with
        // `--no-dev`) the application starts all the same.
        if (class_exists(Scramble::class)) {
            Scramble::ignoreDefaultRoutes();
        }
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

        $this->configureAuthRateLimiters();
    }

    /**
     * The limits of docs/api/auth/, each multiplied by `auth.rate_limit_factor`
     * (AUTH_RATE_LIMIT_FACTOR; 1 when unset; raised in development and for the browser tests).
     * The counters live in the cache, which is Redis. An email address in a key is hashed.
     * The failed sign-in attempts per email and address are counted by AttemptLogin.
     */
    private function configureAuthRateLimiters(): void
    {
        $times = static fn (int $limit): int => $limit * Config::integer('auth.rate_limit_factor');
        $ip = static fn (Request $request): string => (string) $request->ip();

        RateLimiter::for('auth-csrf', fn (Request $request) => Limit::perMinute($times(60))->by($ip($request)));
        RateLimiter::for('auth-register', fn (Request $request) => Limit::perHour($times(10))->by($ip($request)));
        RateLimiter::for('auth-login', fn (Request $request) => Limit::perMinute($times(10))->by($ip($request)));
        RateLimiter::for('auth-verify-email', fn (Request $request) => Limit::perMinute($times(10))->by($ip($request)));
        RateLimiter::for('auth-reset-password', fn (Request $request) => Limit::perHour($times(10))->by($ip($request)));

        // 3 a minute for one user.
        RateLimiter::for('auth-resend', function (Request $request) use ($times, $ip) {
            $user = $request->user();

            return Limit::perMinute($times(3))->by($user instanceof User ? 'user:'.$user->uuid : $ip($request));
        });

        // 5 an hour for one address, and 3 an hour for one email address, whether it has an
        // account or not.
        RateLimiter::for('auth-forgot-password', function (Request $request) use ($times, $ip) {
            $limits = [Limit::perHour($times(5))->by('ip:'.$ip($request))];
            $email = $request->input('email');

            if (is_string($email) && $email !== '') {
                $limits[] = Limit::perHour($times(3))->by('email:'.hash('sha256', mb_strtolower(trim($email))));
            }

            return $limits;
        });
    }
}

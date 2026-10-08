<?php

namespace App\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The wrong passwords of one account: a single counter in the cache, 5 in 15 minutes (a fixed
 * window from the first failure), keyed by a hash of the user's UUID. It is fed by the wrong
 * passwords at sign-in (from any address) and on the routes that ask for the password again.
 * It never refuses a sign-in and a sign-in does not clear it; it only locks those routes
 * (see ConfirmOwnPassword).
 */
final class PasswordFailures
{
    public const MAX = 5;

    public const WINDOW_SECONDS = 900;

    public static function key(User $user): string
    {
        return 'password-failures:'.hash('sha256', $user->uuid);
    }

    /** One more wrong password; returns the count, this one included. */
    public static function hit(User $user): int
    {
        return RateLimiter::hit(self::key($user), self::WINDOW_SECONDS);
    }

    public static function clear(User $user): void
    {
        RateLimiter::clear(self::key($user));
    }

    public static function secondsLeft(User $user): int
    {
        return max(1, RateLimiter::availableIn(self::key($user)));
    }

    /** Ends the session of the request and answers 401. */
    public static function endSession(Request $request): never
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Log::info('auth.two_factor_password', ['outcome' => 'locked']);

        throw new ApiException(401, 'unauthenticated');
    }

    public static function locked(User $user): ThrottleRequestsException
    {
        Log::info('auth.two_factor_password', ['outcome' => 'throttled']);

        return new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => (string) self::secondsLeft($user)]);
    }
}

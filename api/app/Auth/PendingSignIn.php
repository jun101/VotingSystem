<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;

/**
 * The sign-in that has the right password and waits for the second factor
 * (docs/api/auth/POST-auth-login.md scenario 10). It lives in the server-side session only:
 * the user's UUID, the time and the number of wrong codes. It lasts five minutes, and the
 * fifth wrong code ends it. Nothing in a request can name the user.
 *
 * Every method takes the session of the request: nothing is kept here between requests.
 */
final class PendingSignIn
{
    private const KEY = 'two_factor_pending';

    public const LIFETIME_SECONDS = 300;

    public const MAX_WRONG_CODES = 5;

    public static function start(Session $session, User $user): void
    {
        $session->put(self::KEY, ['user' => $user->uuid, 'at' => now()->getTimestamp(), 'wrong' => 0]);
    }

    /** The UUID of the user waiting for the second step, or null (none, expired, ended). */
    public static function userUuid(Session $session): ?string
    {
        $pending = $session->get(self::KEY);

        if (! is_array($pending) || ! is_string($pending['user'] ?? null) || ! is_int($pending['at'] ?? null) || ! is_int($pending['wrong'] ?? null)) {
            self::end($session);

            return null;
        }

        if (now()->getTimestamp() - $pending['at'] > self::LIFETIME_SECONDS || $pending['wrong'] >= self::MAX_WRONG_CODES) {
            self::end($session);

            return null;
        }

        return $pending['user'];
    }

    /** Counts a wrong code; the fifth ends the pending sign-in. */
    public static function countWrongCode(Session $session): void
    {
        $pending = $session->get(self::KEY);

        if (! is_array($pending) || ! is_int($pending['wrong'] ?? null)) {
            return;
        }

        $pending['wrong']++;

        if ($pending['wrong'] >= self::MAX_WRONG_CODES) {
            self::end($session);

            return;
        }

        $session->put(self::KEY, $pending);
    }

    public static function end(Session $session): void
    {
        $session->forget(self::KEY);
    }
}

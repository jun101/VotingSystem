<?php

namespace App\Auth;

use App\Models\User;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

/**
 * The sign-in that has the right password and waits for the second factor
 * (docs/api/auth/POST-auth-login.md scenario 10). It lives in the server-side session only:
 * the user's UUID, the time, a hash of the user's password hash (a password changed in between
 * ends it) and a random nonce that keys the wrong-code counter in the cache, and the key of the failed-password counter of the address the password was typed from. It lasts five
 * minutes, and the fifth wrong code ends it. Nothing in a request can name the user.
 *
 * The wrong codes are counted in the cache, with one atomic increment per attempt made
 * before the code is judged, so parallel requests cannot go over the limit.
 *
 * Every method takes the session of the request: nothing is kept here between requests.
 */
final class PendingSignIn
{
    private const KEY = 'two_factor_pending';

    public const LIFETIME_SECONDS = 300;

    public const MAX_WRONG_CODES = 5;

    public static function start(Session $session, User $user, ?string $failureKey = null): void
    {
        $session->put(self::KEY, [
            'user' => $user->uuid,
            'at' => now()->getTimestamp(),
            'pw' => self::passwordFingerprint($user),
            'nonce' => Str::random(40),
            'fail' => $failureKey,
        ]);
    }

    /** A hash of the user's password hash: it changes when the password does. */
    public static function passwordFingerprint(User $user): string
    {
        return hash('sha256', 'two-factor-pending|'.$user->password);
    }

    /**
     * The pending sign-in, or null (none, expired, ended).
     *
     * @return array{user: string, at: int, pw: string, nonce: string, fail: string|null}|null
     */
    public static function current(Session $session): ?array
    {
        $pending = $session->get(self::KEY);

        if (! is_array($pending)
            || ! is_string($pending['user'] ?? null)
            || ! is_int($pending['at'] ?? null)
            || ! is_string($pending['pw'] ?? null)
            || ! is_string($pending['nonce'] ?? null)
            || ! (is_string($pending['fail'] ?? null) || ($pending['fail'] ?? null) === null)
            || now()->getTimestamp() - $pending['at'] > self::LIFETIME_SECONDS) {
            self::end($session);

            return null;
        }

        return ['user' => $pending['user'], 'at' => $pending['at'], 'pw' => $pending['pw'], 'nonce' => $pending['nonce'], 'fail' => $pending['fail'] ?? null];
    }

    /**
     * Counts a guess before it is judged and returns how many this sign-in has made, this one
     * included. Above `MAX_WRONG_CODES` the guess must be refused, not judged.
     *
     * @param  array{nonce: string}  $pending
     */
    public static function reserveGuess(array $pending): int
    {
        return RateLimiter::hit(self::counterKey($pending), self::LIFETIME_SECONDS);
    }

    /** @param  array{nonce: string}  $pending */
    public static function clearGuesses(array $pending): void
    {
        RateLimiter::clear(self::counterKey($pending));
    }

    public static function end(Session $session): void
    {
        $session->forget(self::KEY);
    }

    /** @param  array{nonce: string}  $pending */
    private static function counterKey(array $pending): string
    {
        return 'two-factor-pending:'.hash('sha256', $pending['nonce']);
    }
}

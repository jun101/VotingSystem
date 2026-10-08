<?php

namespace App\Actions\Auth;

use App\Auth\PendingSignIn;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Session\Session;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The second step of signing in (docs/api/auth/POST-auth-two-factor-challenge.md). It reads the
 * pending sign-in from the session and nothing else names the user. Returns the user to sign
 * in; the controller opens the session.
 *
 * Order: pending sign-in missing, expired or ended (401), the password changed since the first
 * step (401), suspended institution (403), the account limit (429), then the code (422).
 *
 * Two counters, both in the cache and both counted with one atomic increment before the code
 * is judged (so parallel requests cannot go over): 5 wrong codes per 15 minutes for the
 * account, whatever the browser or the address, and 5 for this pending sign-in, which the fifth
 * ends. A value that is not a string, or is over 32 characters, is a wrong code that is never
 * hashed or compared. A request with neither field is counted by neither. A recovery code is
 * checked first when sent.
 */
final class CompleteTwoFactorChallenge
{
    public const MAX_WRONG_PER_ACCOUNT = 5;

    public const ACCOUNT_WINDOW_SECONDS = 900;

    private const MAX_LENGTH = 32;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function __invoke(Session $session, mixed $code, mixed $recoveryCode, string $ip): User
    {
        $pending = PendingSignIn::current($session);

        if ($pending === null) {
            Log::info('auth.two_factor_challenge', ['outcome' => 'expired']);

            throw new ApiException(401, 'unauthenticated');
        }

        // The user of a pending sign-in is found by the UUID the first step stored in the
        // session, before anybody is signed in, so there is no institution to narrow the lookup.
        $user = User::withoutInstitutionScope()->where('uuid', $pending['user'])->first();

        if ($user === null || ! $user->hasTwoFactorEnabled() || ! is_string($user->two_factor_secret)
            || ! hash_equals($pending['pw'], PendingSignIn::passwordFingerprint($user))) {
            PendingSignIn::end($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'expired']);

            throw new ApiException(401, 'unauthenticated');
        }

        if ($user->institution?->isSuspended()) {
            PendingSignIn::end($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'suspended']);

            throw new ApiException(403, 'institution_suspended');
        }

        $accountKey = self::accountKey($user);

        if (RateLimiter::tooManyAttempts($accountKey, self::MAX_WRONG_PER_ACCOUNT)) {
            throw $this->throttled($accountKey);
        }

        $field = $this->present($recoveryCode) ? 'recovery_code' : 'code';
        $given = $field === 'recovery_code' ? $recoveryCode : $code;

        if (! $this->present($given)) {
            throw ValidationException::withMessages(['code' => ['required']]);
        }

        // Counted before the code is judged; a success clears both counts below.
        if (RateLimiter::hit($accountKey, self::ACCOUNT_WINDOW_SECONDS) > self::MAX_WRONG_PER_ACCOUNT) {
            throw $this->throttled($accountKey);
        }

        $guesses = PendingSignIn::reserveGuess($pending);

        if ($guesses > PendingSignIn::MAX_WRONG_CODES) {
            PendingSignIn::end($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'expired']);

            throw new ApiException(401, 'unauthenticated');
        }

        $accepted = is_string($given) && strlen($given) <= self::MAX_LENGTH && ($field === 'recovery_code'
            ? $this->useRecoveryCode($user, $given)
            : $this->useCode($user, $user->two_factor_secret, $given));

        if (! $accepted) {
            if ($guesses >= PendingSignIn::MAX_WRONG_CODES) {
                PendingSignIn::end($session);
            }
            Log::info('auth.two_factor_challenge', ['outcome' => 'invalid']);

            throw ValidationException::withMessages([$field => ['invalid']]);
        }

        RateLimiter::clear($accountKey);
        PendingSignIn::clearGuesses($pending);
        PendingSignIn::end($session);
        // As a sign-in without two-factor does at its only step.
        RateLimiter::clear(AttemptLogin::failureKeyForUser($user, $ip));
        $user->last_login_at = now();
        $user->save();

        Log::info('auth.two_factor_challenge', ['outcome' => 'success']);

        return $user;
    }

    /** The wrong-code counter of an account; the key holds a hash of the UUID. */
    public static function accountKey(User $user): string
    {
        return 'two-factor-wrong:'.hash('sha256', $user->uuid);
    }

    private function throttled(string $accountKey): ThrottleRequestsException
    {
        Log::info('auth.two_factor_challenge', ['outcome' => 'throttled']);

        return new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => (string) max(1, RateLimiter::availableIn($accountKey))]);
    }

    private function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    private function useCode(User $user, string $secret, string $code): bool
    {
        $step = $this->twoFactor->matchingStep($secret, $code, $user->two_factor_last_step);

        return $step !== null && $this->twoFactor->claimStep($user, $step);
    }

    /** Removes the code from the list in the same transaction that finds it, with the row locked. */
    private function useRecoveryCode(User $user, string $code): bool
    {
        return DB::transaction(function () use ($user, $code): bool {
            // A fresh read of the user's own row, locked: two requests with the same code cannot both pass.
            $locked = $user->newModelQuery()->whereKey($user->getKey())->lockForUpdate()->first();

            if (! $locked instanceof User) {
                return false;
            }

            $hashes = $this->twoFactor->recoveryHashes($locked);
            $position = $this->twoFactor->findRecoveryCode($hashes, $code);

            if ($position === null) {
                return false;
            }

            unset($hashes[$position]);
            $this->twoFactor->storeRecoveryHashes($locked, array_values($hashes));
            $locked->save();

            return true;
        });
    }
}

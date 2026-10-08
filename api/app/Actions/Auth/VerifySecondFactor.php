<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * A current second factor asked with the password on the routes that change it: turning
 * two-factor off, renewing the recovery codes and an owner's reset of another user. The caller
 * has already checked the password and the state of the account.
 *
 * It uses the account's wrong-code counter, the one of the sign-in challenge (5 per 15
 * minutes, in the cache): from the sixth, 429 with Retry-After even for a right code. A try is
 * counted with one atomic increment before it is judged, so parallel guesses cannot go over; a
 * right one clears the count. A value that is not a string, or is over 32 characters, is wrong
 * and is never hashed or compared. `recovery_code` is checked first when both are sent. A right
 * code marks its period used; a right recovery code is removed from the list under the row lock.
 */
final class VerifySecondFactor
{
    public const MAX_LENGTH = 32;

    public function __construct(private readonly TwoFactor $twoFactor) {}

    /**
     * @throws ThrottleRequestsException when the account has too many wrong codes
     * @throws ValidationException `code: required` or `code|recovery_code: invalid`
     */
    public function __invoke(User $user, mixed $code, mixed $recoveryCode): void
    {
        $key = CompleteTwoFactorChallenge::accountKey($user);

        if (RateLimiter::tooManyAttempts($key, CompleteTwoFactorChallenge::MAX_WRONG_PER_ACCOUNT)) {
            throw self::throttled($key);
        }

        $field = self::present($recoveryCode) ? 'recovery_code' : 'code';
        $given = $field === 'recovery_code' ? $recoveryCode : $code;

        if (! self::present($given)) {
            throw ValidationException::withMessages(['code' => ['required']]);
        }

        if (RateLimiter::hit($key, CompleteTwoFactorChallenge::ACCOUNT_WINDOW_SECONDS) > CompleteTwoFactorChallenge::MAX_WRONG_PER_ACCOUNT) {
            throw self::throttled($key);
        }

        if (! $this->accepts($user, $field, $given)) {
            Log::info('auth.two_factor_second_factor', ['outcome' => 'invalid']);

            throw ValidationException::withMessages([$field => ['invalid']]);
        }

        RateLimiter::clear($key);
    }

    /** Whether the value is right for the user; a right one is used up. Never throws for a bad value. */
    public function accepts(User $user, string $field, mixed $given): bool
    {
        if (! is_string($given) || strlen($given) > self::MAX_LENGTH) {
            return false;
        }

        if ($field === 'recovery_code') {
            return $this->useRecoveryCode($user, $given);
        }

        if (! is_string($user->two_factor_secret)) {
            return false;
        }

        $step = $this->twoFactor->matchingStep($user->two_factor_secret, $given, $user->two_factor_last_step);

        return $step !== null && $this->twoFactor->claimStep($user, $step);
    }

    public static function present(mixed $value): bool
    {
        return $value !== null && $value !== '' && $value !== [];
    }

    public static function throttled(string $accountKey): ThrottleRequestsException
    {
        Log::info('auth.two_factor_second_factor', ['outcome' => 'throttled']);

        return new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => (string) max(1, RateLimiter::availableIn($accountKey))]);
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

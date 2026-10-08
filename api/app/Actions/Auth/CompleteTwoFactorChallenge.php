<?php

namespace App\Actions\Auth;

use App\Auth\PendingSignIn;
use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\TwoFactor;
use Illuminate\Contracts\Session\Session;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The second step of signing in (docs/api/auth/POST-auth-two-factor-challenge.md). It reads the
 * pending sign-in from the session and nothing else names the user. Returns the user to sign
 * in; the controller opens the session.
 *
 * Order: pending sign-in (401), suspended institution (403), the code (422). A wrong code
 * counts, the fifth ends the pending sign-in. A recovery code is checked first when sent.
 */
final class CompleteTwoFactorChallenge
{
    public function __construct(private readonly TwoFactor $twoFactor) {}

    public function __invoke(Session $session, mixed $code, mixed $recoveryCode): User
    {
        $uuid = PendingSignIn::userUuid($session);

        if ($uuid === null) {
            Log::info('auth.two_factor_challenge', ['outcome' => 'expired']);

            throw new ApiException(401, 'unauthenticated');
        }

        // The user of a pending sign-in is found by the UUID the first step stored in the
        // session, before anybody is signed in, so there is no institution to narrow the lookup.
        $user = User::withoutInstitutionScope()->where('uuid', $uuid)->first();

        if ($user === null || ! $user->hasTwoFactorEnabled() || ! is_string($user->two_factor_secret)) {
            PendingSignIn::end($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'expired']);

            throw new ApiException(401, 'unauthenticated');
        }

        if ($user->institution?->isSuspended()) {
            PendingSignIn::end($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'suspended']);

            throw new ApiException(403, 'institution_suspended');
        }

        $field = $this->present($recoveryCode) ? 'recovery_code' : 'code';
        $given = $field === 'recovery_code' ? $recoveryCode : $code;

        if (! $this->present($given)) {
            throw ValidationException::withMessages(['code' => ['required']]);
        }

        $accepted = is_string($given) && ($field === 'recovery_code'
            ? $this->useRecoveryCode($user, $given)
            : $this->useCode($user, $user->two_factor_secret, $given));

        if (! $accepted) {
            PendingSignIn::countWrongCode($session);
            Log::info('auth.two_factor_challenge', ['outcome' => 'invalid']);

            throw ValidationException::withMessages([$field => ['invalid']]);
        }

        PendingSignIn::end($session);
        $user->last_login_at = now();
        $user->save();

        Log::info('auth.two_factor_challenge', ['outcome' => 'success']);

        return $user;
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

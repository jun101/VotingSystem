<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Takes the second factor off a user: the secret, the recovery codes, the confirmation time
 * and the stored period are all cleared (the three ways to do it: the user's own `disable`,
 * the owner's reset and the operator's command). Open sessions are left alone.
 */
final class ClearTwoFactor
{
    public function __invoke(User $user): void
    {
        // Read again: a period claimed or a recovery code used a moment ago was written by
        // statements that did not touch this copy, and a field that looks unchanged would not be saved.
        $user->refresh();
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();

        // A wrong-code count belongs to the factor that is gone.
        RateLimiter::clear(CompleteTwoFactorChallenge::accountKey($user));
    }
}

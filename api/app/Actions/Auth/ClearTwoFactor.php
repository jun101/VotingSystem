<?php

namespace App\Actions\Auth;

use App\Models\User;

/**
 * Takes the second factor off a user: the secret, the recovery codes, the confirmation time
 * and the stored period are all cleared (the three ways to do it: the user's own `disable`,
 * the owner's reset and the operator's command). Open sessions are left alone.
 */
final class ClearTwoFactor
{
    public function __invoke(User $user): void
    {
        $user->two_factor_secret = null;
        $user->two_factor_recovery_codes = null;
        $user->two_factor_confirmed_at = null;
        $user->two_factor_last_step = null;
        $user->save();
    }
}

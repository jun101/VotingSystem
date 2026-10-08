<?php

namespace App\Actions\Auth;

use App\Auth\PasswordFailures;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * The password asked again for a change of the second factor: the user's own at setup, disable
 * and recovery codes, and an owner's at the reset of another user's.
 *
 * Wrong passwords are counted for the account in one shared counter (App\Auth\PasswordFailures,
 * also fed by sign-in), by one atomic increment made before the password is judged, so parallel
 * guesses cannot go over. A wrong one answers 422 `password: incorrect`; the fifth ends the
 * session (401). While the count is over 5 any attempt answers 429 with Retry-After, whatever
 * the password, and the session is kept. A right password clears the count while it is
 * below 5.
 */
final class ConfirmOwnPassword
{
    public function __invoke(Request $request, User $user, string $password): void
    {
        $attempts = PasswordFailures::hit($user);

        if ($attempts > PasswordFailures::MAX) {
            throw PasswordFailures::locked($user);
        }

        if (Hash::check($password, $user->password)) {
            PasswordFailures::clear($user);

            return;
        }

        Log::info('auth.two_factor_password', ['outcome' => 'incorrect']);

        if ($attempts >= PasswordFailures::MAX) {
            PasswordFailures::endSession($request);
        }

        throw ValidationException::withMessages(['password' => ['incorrect']]);
    }
}

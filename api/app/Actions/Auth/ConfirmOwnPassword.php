<?php

namespace App\Actions\Auth;

use App\Auth\PasswordFailures;
use App\Enums\Role;
use App\Models\User;
use App\Support\Platform\Impersonation;
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
        // A platform admin acting as an owner: the admin's own password is asked, and the failures are the admin's.
        $by = Impersonation::by();

        if ($by !== null) {
            // The admin has no institution, so the tenant scope would match nobody: the session names the admin's key.
            $admin = User::withoutInstitutionScope()->whereKey($by['id'])->where('role', Role::PlatformAdmin->value)->first();

            if ($admin === null) {
                PasswordFailures::endSession($request);
            }

            $user = $admin;
        }

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

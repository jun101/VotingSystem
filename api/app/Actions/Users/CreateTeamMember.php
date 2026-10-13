<?php

namespace App\Actions\Users;

use App\Enums\Role;
use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * School mode: an owner creates a user of their institution directly, with a one-time password (docs/api/users/POST-users.md).
 * No email; the address is only the sign-in identifier. The person must change the password at the first sign-in.
 */
final class CreateTeamMember
{
    /** @return array{0: User, 1: string} the user and the one-time password (shown once, never stored in clear) */
    public function __invoke(User $owner, string $name, string $email, Role $role): array
    {
        $password = OneTimePassword::make();

        $user = new User(['name' => $name, 'email' => $email, 'password' => Hash::make($password), 'language' => $owner->language]);
        $user->role = $role;
        $user->institution_id = $owner->institution_id;
        $user->email_verified_at = now();
        $user->must_change_password = true;

        try {
            $user->save();
        } catch (UniqueConstraintViolationException) {
            // Two creations of the same address at the same moment: the second one loses.
            throw ValidationException::withMessages(['email' => ['taken']]);
        }

        Log::info('users.create', ['outcome' => 'created']);

        return [$user, $password];
    }
}

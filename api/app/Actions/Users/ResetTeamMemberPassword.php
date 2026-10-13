<?php

namespace App\Actions\Users;

use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * School mode: a new one-time password for a user of the owner's institution. The user's other sessions end by themselves:
 * a session carries a hash of the password it was opened with (Illuminate\Session\Middleware\AuthenticateSession).
 */
final class ResetTeamMemberPassword
{
    /** @return string the one-time password, shown once */
    public function __invoke(User $user): string
    {
        $password = OneTimePassword::make();

        $user->password = Hash::make($password);
        $user->must_change_password = true;
        $user->save();

        Log::info('users.password_reset', ['outcome' => 'reset']);

        return $password;
    }
}

<?php

namespace App\Actions\Auth;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * A signed-in user changes their own password (docs/api/auth/POST-auth-change-password.md). The current password is
 * asked again (counted with the wrong passwords of sign-in), then the second factor when the user has one. The other
 * sessions end (their stored hash no longer matches); this one is given the new hash and a new id.
 */
final class ChangeOwnPassword
{
    public function __construct(private readonly ConfirmOwnPassword $confirm, private readonly VerifySecondFactor $verifySecondFactor) {}

    public function __invoke(Request $request, User $user, string $current, string $new, mixed $code, mixed $recoveryCode): void
    {
        try {
            ($this->confirm)($request, $user, $current);
        } catch (ValidationException $e) {
            // The shared check names its field `password`; this endpoint's is `current_password`.
            throw ValidationException::withMessages(['current_password' => $e->errors()['password'] ?? ['incorrect']]);
        }

        if ($user->hasTwoFactorEnabled()) {
            ($this->verifySecondFactor)($user, $code, $recoveryCode);
        }

        if (mb_strtolower(trim($new)) === mb_strtolower($user->email)) {
            throw ValidationException::withMessages(['password' => ['same_as_email']]);
        }

        if (Hash::check($new, $user->password)) {
            throw ValidationException::withMessages(['password' => ['unchanged']]);
        }

        $user->password = Hash::make($new);
        $user->must_change_password = false;
        $user->save();

        $request->session()->put('password_hash_'.Auth::getDefaultDriver(), $user->getAuthPassword());
        $request->session()->regenerate();

        Log::info('auth.change_password', ['outcome' => 'changed']);
    }
}

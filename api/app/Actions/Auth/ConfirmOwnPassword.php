<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;

/**
 * The password asked again for a change of the second factor: the user's own at setup, disable
 * and recovery codes, and an owner's at the reset of another user's.
 *
 * Wrong passwords are counted for the user, in the cache, by one atomic increment made before
 * the password is judged (so parallel guesses cannot go over): the count is shared by the five
 * routes. A wrong one answers 422 `password: incorrect`; the fifth in 15 minutes, and every
 * attempt after it inside the window, ends the session and answers 401. A right password
 * clears the count.
 */
final class ConfirmOwnPassword
{
    public const MAX_WRONG = 5;

    public const WINDOW_SECONDS = 900;

    public function __invoke(Request $request, User $user, string $password): void
    {
        $key = 'two-factor-password:'.hash('sha256', $user->uuid);
        $attempts = RateLimiter::hit($key, self::WINDOW_SECONDS);

        if ($attempts > self::MAX_WRONG) {
            $this->endSession($request);
        }

        if (Hash::check($password, $user->password)) {
            RateLimiter::clear($key);

            return;
        }

        Log::info('auth.two_factor_password', ['outcome' => 'incorrect']);

        if ($attempts >= self::MAX_WRONG) {
            $this->endSession($request);
        }

        throw ValidationException::withMessages(['password' => ['incorrect']]);
    }

    private function endSession(Request $request): never
    {
        Auth::guard()->logout();
        $request->session()->invalidate();
        $request->session()->regenerateToken();
        Log::info('auth.two_factor_password', ['outcome' => 'locked']);

        throw new ApiException(401, 'unauthenticated');
    }
}

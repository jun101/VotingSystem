<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use Illuminate\Http\Exceptions\ThrottleRequestsException;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

/**
 * Checks an email and a password (docs/api/auth/POST-auth-login.md). It does not open the
 * session: the controller does, with the user returned.
 *
 * - Five failed attempts a minute for one account (or one unknown email) and one address, then 429 even with the
 *   right password. The counter holds a hash of the pair, never the address itself.
 * - An unknown email, a removed user and a wrong password give the same answer, in about
 *   the same time: a hash is computed in every case.
 * - The institution's suspension is told only to someone who knows the password.
 */
final class AttemptLogin
{
    private const MAX_FAILURES_PER_MINUTE = 5;

    public function __invoke(string $email, string $password, string $ip): User
    {
        $email = mb_strtolower(trim($email));
        // Nobody is signed in yet at sign-in, and the email is unique across every institution,
        // so the lookup has to cross tenants.
        $user = User::withoutInstitutionScope()->where('email', $email)->first();

        // The counter follows the account, not the spelling: the database ignores accents and
        // case, so `josé@` and `jose@` are one user. An address with no account is counted by
        // its text. Either way the key is a hash.
        $key = 'login-failures:'.hash('sha256', ($user === null ? 'email:'.$email : 'user:'.$user->uuid).'|'.$ip);
        $max = self::MAX_FAILURES_PER_MINUTE * Config::integer('auth.rate_limit_factor');

        if (RateLimiter::tooManyAttempts($key, $max)) {
            Log::info('auth.login', ['outcome' => 'throttled']);

            throw new ThrottleRequestsException('Too many attempts.', null, ['Retry-After' => (string) max(1, RateLimiter::availableIn($key))]);
        }

        if ($user === null) {
            // Same work as for a known user: a hash, whose result is thrown away.
            Hash::make($password);
        }

        if ($user === null || ! Hash::check($password, $user->password)) {
            RateLimiter::hit($key, 60);
            Log::info('auth.login', ['outcome' => 'invalid_credentials']);

            throw new ApiException(401, 'invalid_credentials');
        }

        if ($user->institution?->isSuspended()) {
            Log::info('auth.login', ['outcome' => 'suspended']);

            throw new ApiException(403, 'institution_suspended');
        }

        RateLimiter::clear($key);

        if (Hash::needsRehash($user->password)) {
            $user->password = Hash::make($password);
        }

        $user->last_login_at = now();
        $user->save();

        Log::info('auth.login', ['outcome' => 'success']);

        return $user;
    }
}

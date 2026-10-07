<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Rules\SameAsEmail;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/**
 * Sets a new password with the token of the reset link, in one transaction. The user is
 * not signed in. Their other sessions end by themselves: a session carries a hash of the
 * password it was opened with, and is refused when it no longer matches
 * (Illuminate\Session\Middleware\AuthenticateSession).
 */
final class ResetPassword
{
    public function __construct(private readonly LinkTokens $tokens) {}

    public function __invoke(string $token, string $password): void
    {
        DB::transaction(function () use ($token, $password): void {
            $row = $this->tokens->find(LinkTokens::RESET, $token, lock: true);
            $user = $row === null ? null : $this->userOf($row->user_id);

            if ($row === null || $user === null) {
                Log::info('auth.reset_password', ['outcome' => 'invalid']);

                throw ValidationException::withMessages(['token' => ['invalid']]);
            }

            if ($this->tokens->isExpired($row)) {
                Log::info('auth.reset_password', ['outcome' => 'expired']);

                throw new ApiException(410, 'expired');
            }

            // Checked before the token is spent: a refused password leaves the link usable.
            (new SameAsEmail($user->email))->validate('password', $password, static function (): never {
                throw ValidationException::withMessages(['password' => ['same_as_email']]);
            });

            $user->password = Hash::make($password);

            // Reaching this link proves the person reads the mailbox.
            $user->email_verified_at ??= now();
            $user->save();

            $this->tokens->forget(LinkTokens::RESET, $row);
            DB::table(LinkTokens::VERIFICATION)->where('user_id', $user->getKey())->delete();
        });

        Log::info('auth.reset_password', ['outcome' => 'reset']);
    }

    /** The owner of a token row. */
    private function userOf(mixed $key): ?User
    {
        // The link is opened with nobody signed in, so the token's owner is found across
        // every institution.
        return User::withoutInstitutionScope()->whereKey($key)->first();
    }
}

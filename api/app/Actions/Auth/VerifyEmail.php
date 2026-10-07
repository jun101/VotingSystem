<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

/** Spends a verification token and marks the user's email as verified, in one transaction. */
final class VerifyEmail
{
    public function __construct(private readonly LinkTokens $tokens) {}

    public function __invoke(string $token): void
    {
        DB::transaction(function () use ($token): void {
            $row = $this->tokens->find(LinkTokens::VERIFICATION, $token, lock: true);
            $user = $row === null ? null : User::query()->whereKey($row->user_id)->first();

            if ($row === null || $user === null) {
                Log::info('auth.verify_email', ['outcome' => 'invalid']);

                throw ValidationException::withMessages(['token' => ['invalid']]);
            }

            // An expired token is kept: nothing is written.
            if ($this->tokens->isExpired($row)) {
                Log::info('auth.verify_email', ['outcome' => 'expired']);

                throw new ApiException(410, 'expired');
            }

            $user->email_verified_at = now();
            $user->save();
            $this->tokens->forget(LinkTokens::VERIFICATION, $row);
        });

        Log::info('auth.verify_email', ['outcome' => 'verified']);
    }
}

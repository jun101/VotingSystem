<?php

namespace App\Actions\Auth;

use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\Log;

/**
 * Sends a reset link to the address if it belongs to a user, and does nothing otherwise.
 * The caller answers the same way in both cases.
 */
final class RequestPasswordReset
{
    public const RESET_MINUTES = 60;

    public function __construct(private readonly LinkTokens $tokens) {}

    public function __invoke(string $email): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($email)))->first();

        if ($user !== null) {
            $token = $this->tokens->issue(LinkTokens::RESET, $user, self::RESET_MINUTES);
            $user->notify(new ResetPasswordNotification($token));
        }

        // The same line whether the address exists or not.
        Log::info('auth.forgot_password', ['outcome' => 'accepted']);
    }
}

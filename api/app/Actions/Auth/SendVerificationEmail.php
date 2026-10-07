<?php

namespace App\Actions\Auth;

use App\Exceptions\ApiException;
use App\Models\User;
use App\Notifications\VerifyEmailNotification;
use App\Support\LinkTokens;
use Illuminate\Support\Facades\Log;

/** A new verification link for a signed-in user; the previous one stops working. */
final class SendVerificationEmail
{
    public function __construct(private readonly LinkTokens $tokens) {}

    public function __invoke(User $user): void
    {
        if ($user->hasVerifiedEmail()) {
            throw new ApiException(409, 'already_verified');
        }

        $token = $this->tokens->issue(LinkTokens::VERIFICATION, $user, RegisterInstitution::VERIFICATION_MINUTES);
        $user->notify(new VerifyEmailNotification($token));

        Log::info('auth.verification_resent', ['outcome' => 'sent']);
    }
}

<?php

namespace App\Actions\Auth;

use App\Jobs\SendPasswordResetLink;
use Illuminate\Support\Facades\Log;

/**
 * Asks for a reset link to be sent to the address if it belongs to a user. The lookup and the
 * sending are in a queued job, so the work done here is the same for any address: one push
 * to the queue. The caller answers the same way in both cases.
 */
final class RequestPasswordReset
{
    public const RESET_MINUTES = 60;

    public function __invoke(string $email): void
    {
        SendPasswordResetLink::dispatch($email);

        // The same line whether the address exists or not.
        Log::info('auth.forgot_password', ['outcome' => 'accepted']);
    }
}

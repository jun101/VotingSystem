<?php

namespace App\Jobs;

use App\Actions\Auth\RequestPasswordReset;
use App\Models\User;
use App\Notifications\ResetPasswordNotification;
use App\Support\LinkTokens;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Looks the address up and, if it belongs to a user, issues a reset token and sends the
 * link. It runs in the queue, so the forgot-password request does the same work for a known
 * and for an unknown address: one push. The payload holds the address, so it is encrypted.
 */
class SendPasswordResetLink implements ShouldBeEncrypted, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public function __construct(private readonly string $email) {}

    public function handle(LinkTokens $tokens): void
    {
        $user = User::query()->where('email', mb_strtolower(trim($this->email)))->first();

        if ($user === null) {
            return;
        }

        $token = $tokens->issue(LinkTokens::RESET, $user, RequestPasswordReset::RESET_MINUTES);
        $user->notify(new ResetPasswordNotification($token));
    }
}

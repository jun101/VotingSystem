<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The verification email (FR-INST-01). Queued: sent by the `queue` service, in the language
 * of the user. The link holds the token and nothing else: no id, no address.
 */
class VerifyEmailNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $token)
    {
        $this->afterCommit();
    }

    /** @return list<string> */
    public function via(object $notifiable): array
    {
        return ['mail'];
    }

    public function toMail(object $notifiable): MailMessage
    {
        return (new MailMessage)
            ->subject(__('mail.verify.subject'))
            ->view(
                ['mail.action', 'mail.action-text'],
                [
                    'name' => data_get($notifiable, 'name'),
                    'intro' => __('mail.verify.intro'),
                    'button' => __('mail.verify.button'),
                    'url' => rtrim(Config::string('app.url'), '/').'/verify-email?token='.$this->token,
                    'validity' => __('mail.verify.validity'),
                    'ignore' => __('mail.verify.ignore'),
                ],
            );
    }
}

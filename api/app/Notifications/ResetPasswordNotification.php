<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The password reset email (FR-INST-04). Queued, in the language of the user. The link
 * holds the token and nothing else: no id, no address.
 */
class ResetPasswordNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
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
            ->subject(__('mail.reset.subject'))
            ->view(
                ['mail.action', 'mail.action-text'],
                [
                    'title' => __('mail.reset.title'),
                    'line' => __('mail.reset.line'),
                    'preheader' => __('mail.reset.preheader'),
                    'intro' => __('mail.reset.intro'),
                    'button' => __('mail.reset.button'),
                    'url' => rtrim(Config::string('app.url'), '/').'/reset-password?token='.$this->token,
                    'validity' => __('mail.reset.validity'),
                    'ignore' => __('mail.reset.ignore'),
                ],
            );
    }
}

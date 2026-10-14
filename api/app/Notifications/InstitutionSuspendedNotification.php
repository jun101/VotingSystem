<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Tells an owner their institution was suspended, with the reason the platform admin gave (FR-INST-06). Built as
 * the other emails (`mail.action`, docs/design/email.md), in the institution's language (the caller sets it with
 * `locale()`). The reason is text typed by a person: control characters were removed when it was stored.
 */
class InstitutionSuspendedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct(private readonly string $reason)
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
            ->subject(__('mail.suspended.subject'))
            ->view(
                ['mail.action', 'mail.action-text'],
                [
                    'title' => __('mail.suspended.title'),
                    'line' => __('mail.suspended.line'),
                    'preheader' => __('mail.suspended.preheader'),
                    'intro' => __('mail.suspended.intro'),
                    'details' => [[__('mail.suspended.reason'), $this->reason]],
                    'button' => __('mail.suspended.button'),
                    'url' => rtrim(Config::string('app.url'), '/').'/login',
                    'validity' => __('mail.suspended.validity'),
                    'ignore' => __('mail.suspended.ignore'),
                ],
            );
    }
}

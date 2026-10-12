<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * Tells an owner their institution is active again (FR-INST-06). No reason in it. Built as the other emails
 * (`mail.action`, docs/design/email.md), in the institution's language (the caller sets it with `locale()`).
 */
class InstitutionReactivatedNotification extends Notification implements ShouldQueue
{
    use Queueable;

    public function __construct()
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
            ->subject(__('mail.reactivated.subject'))
            ->view(
                ['mail.action', 'mail.action-text'],
                [
                    'title' => __('mail.reactivated.title'),
                    'line' => __('mail.reactivated.line'),
                    'preheader' => __('mail.reactivated.preheader'),
                    'intro' => __('mail.reactivated.intro'),
                    'button' => __('mail.reactivated.button'),
                    'url' => rtrim(Config::string('app.url'), '/').'/login',
                    'validity' => __('mail.reactivated.validity'),
                    'ignore' => __('mail.reactivated.ignore'),
                ],
            );
    }
}

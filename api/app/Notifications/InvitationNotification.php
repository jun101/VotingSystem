<?php

namespace App\Notifications;

use App\Enums\Role;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeEncrypted;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\MailMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Facades\Config;

/**
 * The invitation email (FR-INST-03). Queued, with an encrypted payload since it holds a
 * token, in the language of the inviting institution (the caller sets it with `locale()`).
 *
 * It names the institution and never the person who invited: a free-text name under the
 * product's name is the phishing risk of the slice 02 review (S6). The link holds the token
 * and nothing else: no id, no address.
 */
class InvitationNotification extends Notification implements ShouldBeEncrypted, ShouldQueue
{
    use Queueable;

    public function __construct(
        private readonly string $token,
        private readonly string $institution,
        private readonly Role $role,
    ) {
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
            ->subject(__('mail.invite.subject'))
            ->view(
                ['mail.action', 'mail.action-text'],
                [
                    'title' => __('mail.invite.title'),
                    'line' => __('mail.invite.line'),
                    'preheader' => __('mail.invite.preheader'),
                    'intro' => __('mail.invite.intro', [
                        'institution' => $this->institution,
                        'role' => __('mail.invite.roles.'.$this->role->value),
                    ]),
                    'button' => __('mail.invite.button'),
                    'url' => rtrim(Config::string('app.url'), '/').'/accept-invitation?token='.$this->token,
                    'validity' => __('mail.invite.validity'),
                    'ignore' => __('mail.invite.ignore'),
                ],
            );
    }
}

<?php

namespace App\Mail;

use Illuminate\Mail\Mailable;
use Illuminate\Mail\Mailables\Content;
use Illuminate\Mail\Mailables\Envelope;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;

/**
 * The email that carries a voter's personal link and access code (FR-CRED-04, FR-CRED-06),
 * built as the other emails (`mail.action`, docs/design/email.md). It names the voter, the
 * election and the institution, never a user of the institution. Sent by `SendCredentialEmail`,
 * which is already in the queue: this mailable is not queued itself. The caller sets the
 * locale with `->locale()`.
 */
final class CredentialMail extends Mailable
{
    public function __construct(
        private readonly string $voterName,
        private readonly string $electionTitle,
        private readonly string $electionUuid,
        private readonly string $institution,
        private readonly Carbon $startsAt,
        private readonly Carbon $endsAt,
        private readonly string $timezone,
        private readonly string $code,
    ) {}

    public function envelope(): Envelope
    {
        return new Envelope(subject: __('mail.credentials.subject'));
    }

    public function content(): Content
    {
        $format = 'd/m/Y H:i';
        $period = __('mail.credentials.period', [
            'from' => $this->startsAt->copy()->setTimezone($this->timezone)->format($format),
            'to' => $this->endsAt->copy()->setTimezone($this->timezone)->format($format),
            'zone' => $this->timezone,
        ]);

        return new Content(
            view: 'mail.action',
            text: 'mail.action-text',
            with: [
                'title' => __('mail.credentials.title'),
                'line' => __('mail.credentials.line'),
                'preheader' => __('mail.credentials.preheader', ['election' => $this->electionTitle]),
                'intro' => __('mail.credentials.intro', [
                    'name' => $this->voterName,
                    'election' => $this->electionTitle,
                    'institution' => $this->institution,
                ]),
                'codeLabel' => __('mail.credentials.code_label'),
                'code' => $this->code,
                // The gradient is only an extra: this email draws on the solid colour alone.
                'solidHero' => true,
                'details' => [
                    [__('mail.credentials.election'), $this->electionTitle],
                    [__('mail.credentials.institution'), $this->institution],
                    [__('mail.credentials.period_label'), $period],
                ],
                'button' => __('mail.credentials.button'),
                'url' => rtrim(Config::string('app.url'), '/').'/vote/'.$this->electionUuid.'#'.$this->code,
                'validity' => __('mail.credentials.validity'),
                'ignore' => __('mail.credentials.ignore'),
            ],
        );
    }
}

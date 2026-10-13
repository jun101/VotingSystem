<?php

namespace App\Console\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\Mail;
use Throwable;

/**
 * Sends one plain message with the mail settings of this installation, so an operator can see at once whether the
 * server can send email (the online installer calls it, and `votectl mail test`). It says what failed and never
 * prints a password or the settings' values other than the host and the port.
 */
class SendTestMail extends Command
{
    protected $signature = 'mail:test {to : The address that receives the test message}';

    protected $description = 'Send a test email with the installation\'s mail settings';

    public function handle(): int
    {
        $to = mb_strtolower(trim((string) $this->argument('to')));

        if (filter_var($to, FILTER_VALIDATE_EMAIL) === false) {
            $this->error('This is not a valid email address.');

            return self::FAILURE;
        }

        $mailer = (string) config('mail.default');
        $host = (string) config("mail.mailers.$mailer.host", '');
        $port = (string) config("mail.mailers.$mailer.port", '');

        try {
            Mail::raw(
                "This is a test message from your VoteSystem installation.\nIf you read it, the server can send email.",
                static function ($message) use ($to): void {
                    $message->to($to)->subject('VoteSystem: test message');
                },
            );
        } catch (Throwable $e) {
            $this->error('The message could not be sent'.($host !== '' ? " through $host:$port" : '').': '.class_basename($e));
            // The reason from the mail server, without the settings that may be in the exception's trace.
            $this->line(mb_substr(preg_replace('/\s+/', ' ', $e->getMessage()) ?? '', 0, 300));

            return self::FAILURE;
        }

        $this->info('Test message sent to '.$to.($host !== '' ? " through $host:$port" : '').'.');

        return self::SUCCESS;
    }
}

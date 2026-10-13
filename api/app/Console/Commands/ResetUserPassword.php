<?php

namespace App\Console\Commands;

use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;

/**
 * Replaces the password of a live user with a one-time password (docs/api/system/COMMAND-school-accounts.md): the
 * way back in for someone who forgot it where no email can be sent. Their sessions end by themselves (the session holds
 * a hash of the old password). The same line and exit code for an unknown or removed address.
 */
class ResetUserPassword extends Command
{
    protected $signature = 'user:reset-password {email : The sign-in address of the user}
        {--credentials-file= : Append the password to this file (created with mode 0600) instead of printing it}';

    protected $description = 'Give a user a new one-time password (the user must change it at the next sign-in)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        // An operator's command: nobody is signed in and an address is unique across every institution, so the lookup
        // crosses tenants. A removed user is not found.
        $user = User::withoutInstitutionScope()->where('email', $email)->first();

        if ($user === null) {
            Log::info('user.operator_password_reset', ['outcome' => 'not_found']);
            $this->error('No user has this address.');

            return self::FAILURE;
        }

        $password = OneTimePassword::make();
        $user->password = Hash::make($password);
        $user->must_change_password = true;
        $user->save();

        Log::info('user.operator_password_reset', ['user' => $user->uuid, 'outcome' => 'reset']);
        $this->line("User: {$user->uuid}");

        $file = (string) $this->option('credentials-file');

        if ($file === '') {
            $this->line("One-time password: {$password}");

            return self::SUCCESS;
        }

        $mask = umask(0177);

        try {
            $written = @file_put_contents($file, "Adresse de connexion / Sign-in address : {$email}\nMot de passe à usage unique / One-time password : {$password}\n\n", FILE_APPEND | LOCK_EX);
        } finally {
            umask($mask);
        }

        if ($written === false) {
            $this->error('The credentials file could not be written; reset the password again.');

            return self::FAILURE;
        }

        @chmod($file, 0600);
        $this->line('Credentials appended to the file.');

        return self::SUCCESS;
    }
}

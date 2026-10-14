<?php

namespace App\Console\Commands;

use App\Enums\InstitutionType;
use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use App\Support\OneTimePassword;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Creates an institution and its first owner with a one-time password, or adds an owner to an existing institution
 * (docs/api/system/COMMAND-school-accounts.md). For an installation with no email: nobody can register there.
 * Any refusal exits 1 and creates nothing. The password is printed once, or appended to a file made for the operator
 * (mode 0600); it is never logged.
 */
class CreateOwner extends Command
{
    protected $signature = 'owner:create {email : The sign-in address of the owner}
        {--institution= : The name of the institution to create}
        {--institution-uuid= : Add the owner to this existing institution instead}
        {--name= : The owner\'s name}
        {--language=fr : fr or en}
        {--credentials-file= : Append the password to this file (created with mode 0600) instead of printing it}';

    protected $description = 'Create an institution and its owner with a one-time password (school mode setup)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));
        $institutionName = trim((string) $this->option('institution'));
        $existing = trim((string) $this->option('institution-uuid'));
        $language = (string) $this->option('language');
        $name = trim((string) $this->option('name'));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            return $this->refuse('This is not a valid email address.');
        }

        if (! in_array($language, ['fr', 'en'], true)) {
            return $this->refuse('--language must be fr or en.');
        }

        if ($institutionName === '' && $existing === '') {
            return $this->refuse('Give --institution (a name) or --institution-uuid.');
        }

        // An operator's command: nobody is signed in, and an address is unique across every institution (a removed user
        // keeps theirs): the lookup crosses tenants and includes removed users.
        if (User::withoutInstitutionScope()->withTrashed()->where('email', $email)->exists()) {
            return $this->refuse('A user already has this email address.');
        }

        $institution = null;

        if ($existing !== '') {
            $institution = Institution::query()->where('uuid', $existing)->first();

            if ($institution === null) {
                return $this->refuse('Unknown institution.');
            }
        }

        $password = OneTimePassword::make();

        [$institution, $user] = DB::transaction(function () use ($institution, $institutionName, $language, $name, $email, $password): array {
            if ($institution === null) {
                $institution = Institution::create([
                    'name' => $institutionName,
                    'type' => InstitutionType::School,
                    'timezone' => 'America/Port-au-Prince',
                    'language' => $language,
                ]);
            }

            $user = new User(['name' => $name !== '' ? $name : 'Responsable', 'email' => $email, 'password' => Hash::make($password), 'language' => $language]);
            $user->role = Role::Owner;
            $user->institution()->associate($institution);
            $user->email_verified_at = Carbon::now('UTC');
            $user->must_change_password = true;
            $user->save();

            return [$institution, $user];
        });

        Log::info('school.owner_created', ['user' => $user->uuid, 'institution' => $institution->uuid]);
        $this->line("Institution: {$institution->uuid}");
        $this->line("Owner: {$user->uuid}");

        return $this->deliver((string) $this->option('credentials-file'), $institution->name, $email, $password);
    }

    private function refuse(string $message): int
    {
        $this->error($message);

        return self::FAILURE;
    }

    /** Appends the credentials to the operator's file (mode 0600), or prints the password once. */
    private function deliver(string $file, string $institution, string $email, string $password): int
    {
        if ($file === '') {
            $this->line("One-time password: {$password}");

            return self::SUCCESS;
        }

        $mask = umask(0177);

        try {
            $written = @file_put_contents($file, "Établissement / Institution : {$institution}\nAdresse de connexion / Sign-in address : {$email}\nMot de passe à usage unique / One-time password : {$password}\n\n", FILE_APPEND | LOCK_EX);
        } finally {
            umask($mask);
        }

        if ($written === false) {
            $this->error('The credentials file could not be written; the account exists: reset its password.');

            return self::FAILURE;
        }

        @chmod($file, 0600);
        $this->line('Credentials appended to the file.');

        return self::SUCCESS;
    }
}

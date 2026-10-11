<?php

namespace App\Console\Commands;

use App\Enums\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Validator;

/**
 * Creates a platform admin (docs/api/platform/COMMAND-platform-create-admin.md): the only way, there is no sign-up
 * for it. The password is asked twice, hidden, and is never printed or logged; the new user is named by uuid.
 * Any refusal exits 1 and creates nothing. No audit entry: the operator runs it outside the application.
 */
class CreatePlatformAdmin extends Command
{
    private const MIN_PASSWORD = 12;

    private const MAX_PASSWORD = 128;

    protected $signature = 'platform:create-admin {email : The email address of the new platform admin}';

    protected $description = 'Create a platform admin (asks for the password)';

    public function handle(): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        if (Validator::make(['email' => $email], ['email' => ['required', 'email:rfc', 'max:255']])->fails()) {
            $this->error('This is not a valid email address.');

            return self::FAILURE;
        }

        // An operator's command, nobody signed in, and an address is unique across every institution (a removed user
        // keeps theirs, in the log): the lookup crosses tenants and includes removed users.
        if (User::withoutInstitutionScope()->withTrashed()->where('email', $email)->exists()) {
            $this->error('A user already has this email address.');

            return self::FAILURE;
        }

        $password = $this->secret('Password');
        $confirmation = $this->secret('Confirm password');
        $password = is_string($password) ? $password : '';
        $confirmation = is_string($confirmation) ? $confirmation : '';

        if (! hash_equals($password, $confirmation)) {
            $this->error('The two passwords are not the same.');

            return self::FAILURE;
        }

        $length = mb_strlen($password);

        if ($length < self::MIN_PASSWORD || $length > self::MAX_PASSWORD) {
            $this->error('The password must have between '.self::MIN_PASSWORD.' and '.self::MAX_PASSWORD.' characters.');

            return self::FAILURE;
        }

        // `name`, `email`, `password` and `language` are fillable; the role and the missing institution are set here.
        $user = new User;
        $user->name = 'Platform admin';
        $user->email = $email;
        $user->password = Hash::make($password);
        $user->language = 'fr';
        $user->role = Role::PlatformAdmin;
        $user->institution_id = null;
        $user->email_verified_at = Carbon::now('UTC');
        $user->save();

        Log::info('platform.admin_created', ['user' => $user->uuid]);
        $this->info("Platform admin created: {$user->uuid}");

        return self::SUCCESS;
    }
}

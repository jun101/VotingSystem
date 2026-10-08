<?php

namespace App\Console\Commands;

use App\Actions\Auth\ClearTwoFactor;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

/**
 * Turns off the two-factor authentication of the user with this email address
 * (docs/slices/04b-two-factor.md, "Operator command"): the only way back in for a last owner
 * who lost both their device and their recovery codes, since no owner is left to ask.
 *
 * Only a live user with two-factor on is changed. Whatever else the address is (unknown,
 * removed, no two-factor) gets the same line and exit code 1, so the command does not tell
 * which addresses have accounts. Neither the output nor the log holds the address: the user
 * is named by UUID.
 */
class ResetTwoFactor extends Command
{
    protected $signature = 'auth:reset-two-factor {email : The email address of the user}';

    protected $description = 'Turn off the two-factor authentication of a user (a locked-out last owner)';

    public function handle(ClearTwoFactor $clear): int
    {
        $email = mb_strtolower(trim((string) $this->argument('email')));

        // An operator's command: nobody is signed in and the address is unique across every
        // institution, so the lookup has to cross tenants. A removed user is not found.
        $user = User::withoutInstitutionScope()->where('email', $email)->first();

        if ($user === null || ! $user->hasTwoFactorEnabled()) {
            Log::info('two_factor.operator_reset', ['outcome' => 'not_reset']);
            $this->error('No user with two-factor authentication turned on has this address.');

            return self::FAILURE;
        }

        $clear($user);

        Log::info('two_factor.operator_reset', ['user' => $user->uuid, 'outcome' => 'reset']);
        $this->info("Two-factor authentication turned off for user {$user->uuid}.");

        return self::SUCCESS;
    }
}

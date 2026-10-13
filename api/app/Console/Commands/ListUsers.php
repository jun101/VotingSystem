<?php

namespace App\Console\Commands;

use App\Models\Institution;
use App\Models\User;
use Illuminate\Console\Command;

/**
 * Lists the live users (docs/api/system/COMMAND-school-accounts.md): name, address, role, institution, two-factor, last
 * sign-in. Never an id, a uuid of a user or a secret.
 */
class ListUsers extends Command
{
    protected $signature = 'user:list {--institution= : Only the users of this institution (uuid)}';

    protected $description = 'List the users of the installation';

    public function handle(): int
    {
        // An operator's command: nobody is signed in, and the list is the whole installation's (or one institution's by uuid).
        $query = User::withoutInstitutionScope()->with('institution')->orderBy('role')->orderBy('name')->orderBy('email');
        $uuid = trim((string) $this->option('institution'));

        if ($uuid !== '') {
            $institution = Institution::query()->where('uuid', $uuid)->first();

            if ($institution === null) {
                $this->error('Unknown institution.');

                return self::FAILURE;
            }

            $query->where('institution_id', $institution->getKey());
        }

        $rows = [];

        foreach ($query->get() as $user) {
            $rows[] = [
                $user->name,
                $user->email,
                $user->role->value,
                $this->institutionName($user),
                $user->hasTwoFactorEnabled() ? 'yes' : 'no',
                $user->last_login_at?->utc()->format('Y-m-d H:i') ?? '-',
            ];
        }

        $this->table(['Name', 'Address', 'Role', 'Institution', 'Two-factor', 'Last sign-in (UTC)'], $rows);

        return self::SUCCESS;
    }

    /** The institution's name, or a dash for a platform admin (who has none). */
    private function institutionName(User $user): string
    {
        $institution = $user->institution;

        return $institution instanceof Institution ? $institution->name : '-';
    }
}

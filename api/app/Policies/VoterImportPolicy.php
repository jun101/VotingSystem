<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\User;
use App\Models\VoterImport;

/**
 * Owners and managers do everything on the voter imports of their own institution. Whether the
 * election's or the import's status allows it is a 409, not decided here.
 */
final class VoterImportPolicy extends TenantPolicy
{
    /** The template and the upload: on an election. */
    public function create(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function view(User $user, VoterImport $import): bool
    {
        return $this->mayManage($user, $import);
    }

    public function update(User $user, VoterImport $import): bool
    {
        return $this->mayManage($user, $import);
    }

    public function delete(User $user, VoterImport $import): bool
    {
        return $this->mayManage($user, $import);
    }

    private function mayManage(User $user, Election|VoterImport $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

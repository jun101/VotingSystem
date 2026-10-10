<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\Party;
use App\Models\User;

/**
 * Owners and managers do everything on the parties of their own institution. Whether the
 * election's status allows a change is a 409, not decided here (`Election::assertEditable()`).
 */
final class PartyPolicy extends TenantPolicy
{
    public function viewAny(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function create(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function update(User $user, Party $party): bool
    {
        return $this->mayManage($user, $party);
    }

    public function delete(User $user, Party $party): bool
    {
        return $this->mayManage($user, $party);
    }

    private function mayManage(User $user, Election|Party $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

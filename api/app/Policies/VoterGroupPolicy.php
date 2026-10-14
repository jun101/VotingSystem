<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\User;
use App\Models\VoterGroup;

/**
 * Owners and managers do everything on the voter groups of their own institution. Whether the
 * election's status allows a change is a 409, not decided here (`Election::assertVotersDeletable()`).
 */
final class VoterGroupPolicy extends TenantPolicy
{
    public function viewAny(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function create(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function update(User $user, VoterGroup $group): bool
    {
        return $this->mayManage($user, $group);
    }

    public function delete(User $user, VoterGroup $group): bool
    {
        return $this->mayManage($user, $group);
    }

    private function mayManage(User $user, Election|VoterGroup $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

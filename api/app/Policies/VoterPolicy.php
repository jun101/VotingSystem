<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\User;
use App\Models\Voter;

/**
 * Owners and managers do everything on the voters of their own institution. Whether the
 * election's status allows a change is a 409, not decided here (`Election::assertVotersEditable()`).
 */
final class VoterPolicy extends TenantPolicy
{
    public function viewAny(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function create(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function update(User $user, Voter $voter): bool
    {
        return $this->mayManage($user, $voter);
    }

    public function delete(User $user, Voter $voter): bool
    {
        return $this->mayManage($user, $voter);
    }

    private function mayManage(User $user, Election|Voter $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Ballot;
use App\Models\Election;
use App\Models\User;

/**
 * Owners and managers do everything on the ballots of their own institution. Whether the
 * election's status allows a change is a 409, not decided here (`Election::assertEditable()`).
 */
final class BallotPolicy extends TenantPolicy
{
    public function viewAny(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function create(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function reorder(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function update(User $user, Ballot $ballot): bool
    {
        return $this->mayManage($user, $ballot);
    }

    public function delete(User $user, Ballot $ballot): bool
    {
        return $this->mayManage($user, $ballot);
    }

    private function mayManage(User $user, Election|Ballot $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

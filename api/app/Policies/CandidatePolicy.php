<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Candidate;
use App\Models\User;

/**
 * Owners and managers do everything on the candidates of their own institution. Whether the
 * election's status allows a change is a 409, not decided here (`Election::assertEditable()`).
 */
final class CandidatePolicy extends TenantPolicy
{
    public function update(User $user, Candidate $candidate): bool
    {
        return $this->mayManage($user, $candidate);
    }

    public function delete(User $user, Candidate $candidate): bool
    {
        return $this->mayManage($user, $candidate);
    }

    private function mayManage(User $user, Candidate $record): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $record);
    }
}

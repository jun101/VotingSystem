<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\Election;
use App\Models\User;

/**
 * Owners and managers do everything on the elections of their own institution. A platform admin
 * has no institution and gets nothing. Whether the election's status allows a change is not
 * decided here (that is a 409, see `Election::assertEditable()`).
 */
final class ElectionPolicy extends TenantPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->isInstitutionUser($user);
    }

    public function create(User $user): bool
    {
        return $this->isInstitutionUser($user);
    }

    public function view(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function update(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function delete(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function duplicate(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    public function changeCover(User $user, Election $election): bool
    {
        return $this->mayManage($user, $election);
    }

    private function isInstitutionUser(User $user): bool
    {
        return $user->institution_id !== null && in_array($user->role, [Role::Owner, Role::Manager], true);
    }

    private function mayManage(User $user, Election $election): bool
    {
        return $this->isInstitutionUser($user) && $this->belongsToUsersInstitution($user, $election);
    }
}

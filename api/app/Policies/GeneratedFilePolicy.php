<?php

namespace App\Policies;

use App\Enums\Role;
use App\Models\GeneratedFile;
use App\Models\User;

/** Owners and managers of the file's institution read and download its files. */
final class GeneratedFilePolicy extends TenantPolicy
{
    public function view(User $user, GeneratedFile $file): bool
    {
        return $user->institution_id !== null
            && in_array($user->role, [Role::Owner, Role::Manager], true)
            && $this->belongsToUsersInstitution($user, $file);
    }
}

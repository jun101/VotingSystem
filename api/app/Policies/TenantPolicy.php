<?php

namespace App\Policies;

use App\Models\User;
use Illuminate\Database\Eloquent\Model;

/**
 * The base of every resource policy. A record of another institution is a 404, never a 403
 * (docs/api/README.md section 4): the route binding already hides it, and this check is the
 * third layer behind it (docs/design/architecture.md section 4.4).
 */
abstract class TenantPolicy
{
    /** True only when the user has an institution and it is the record's. False for a platform admin. */
    public function belongsToUsersInstitution(User $user, Model $record): bool
    {
        $mine = $user->institution_id;
        $theirs = $record->getAttribute('institution_id');

        return $mine !== null && is_numeric($theirs) && $mine === (int) $theirs;
    }
}

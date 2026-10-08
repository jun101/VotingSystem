<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * A user as the owners of their institution see them (docs/api/users/GET-users.md). Never a
 * password, a hash, a secret or a database key.
 *
 * @property User $resource
 */
final class TeamMemberResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $user = $this->resource;
        $viewer = $request->user();

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'email_verified' => $user->hasVerifiedEmail(),
            'last_login_at' => $user->last_login_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'is_you' => $viewer instanceof User && $viewer->is($user),
        ];
    }
}

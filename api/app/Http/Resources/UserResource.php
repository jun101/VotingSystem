<?php

namespace App\Http\Resources;

use App\Models\User;
use Illuminate\Http\Request;

/**
 * The current user, and their institution (`null` for a platform admin). The response of
 * `GET /auth/me`, `POST /auth/login` and `POST /auth/register`, and the resource every
 * later endpoint reuses for "the current user". Never a password, a hash, a secret or a
 * database key.
 *
 * @property User $resource
 */
final class UserResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $user = $this->resource;

        return [
            'name' => $user->name,
            'email' => $user->email,
            'role' => $user->role->value,
            'email_verified' => $user->hasVerifiedEmail(),
            'language' => $user->language,
            'institution' => $user->institution === null ? null : new InstitutionResource($user->institution),
        ];
    }
}

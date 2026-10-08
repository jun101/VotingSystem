<?php

namespace App\Http\Resources;

use App\Models\Invitation;
use Illuminate\Http\Request;

/**
 * An invitation that has not been accepted (docs/api/users/GET-invitations.md). The token and
 * its hash are never in it; the inviter is named by name, not by key.
 *
 * @property Invitation $resource
 */
final class InvitationResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $invitation = $this->resource;

        return [
            'email' => $invitation->email,
            'role' => $invitation->role->value,
            'invited_by' => $invitation->inviter?->name,
            'created_at' => $invitation->created_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'expires_at' => $invitation->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'expired' => $invitation->isExpired(),
        ];
    }
}

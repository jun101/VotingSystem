<?php

namespace App\Http\Resources;

use App\Models\Voter;
use Illuminate\Http\Request;

/**
 * A voter as docs/api/voters/GET-elections-{election}-voters.md shows it. No election, no
 * institution, no numeric key; the group is `{id, name}` or null.
 *
 * @property Voter $resource
 */
final class VoterResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $voter = $this->resource;
        $group = $voter->group;
        // The status of the voter's code only: the code and its hash never leave the server.
        $credential = $voter->credential;

        return [
            'full_name' => $voter->full_name,
            'group' => $group === null ? null : new VoterGroupRefResource($group),
            'identifier' => $voter->identifier,
            'email' => $voter->email,
            'phone' => $voter->phone,
            'has_code' => $credential !== null,
            'email_status' => $credential?->email_status,
            'created_at' => $voter->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $voter->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

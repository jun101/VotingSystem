<?php

namespace App\Http\Resources;

use App\Models\GeneratedFile;
use Illuminate\Http\Request;

/**
 * A file of slips as docs/api/credentials/POST-elections-{election}-credential-runs.md shows it.
 * No stored name, no path, no numeric key; the group is `{id, name}` or null.
 *
 * @property GeneratedFile $resource
 */
final class GeneratedFileResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $file = $this->resource;
        $group = $file->voter_group_id === null ? null : $file->group;

        return [
            'kind' => $file->kind,
            'group' => $group === null ? null : new VoterGroupRefResource($group),
            'part' => $file->part,
            'parts' => $file->parts,
            'voter_count' => $file->voter_count,
            'status' => $file->status,
            'failure' => $file->failure,
            'size_bytes' => $file->size_bytes,
            'expires_at' => $file->expires_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'created_at' => $file->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

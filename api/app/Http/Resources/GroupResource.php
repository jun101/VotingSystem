<?php

namespace App\Http\Resources;

use App\Models\VoterGroup;
use Illuminate\Http\Request;

/**
 * A group as docs/api/groups/GET-elections-{election}-groups.md shows it.
 *
 * @property VoterGroup $resource
 */
final class GroupResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $group = $this->resource;

        return [
            'name' => $group->name,
            // The list counts in its query; a group answered alone counts here.
            'voters_count' => is_numeric($group->getAttribute('voters_count'))
                ? (int) $group->getAttribute('voters_count')
                : $group->voters()->count(),
            'created_at' => $group->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $group->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

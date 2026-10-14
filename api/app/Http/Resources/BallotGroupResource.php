<?php

namespace App\Http\Resources;

use App\Models\VoterGroup;
use Illuminate\Http\Request;

/**
 * A group as a ballot shows it: its UUID as `id`, its name and its voter count.
 *
 * @property VoterGroup $resource
 */
final class BallotGroupResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'voters_count' => $this->resource->votersCounted(),
        ];
    }
}

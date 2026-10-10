<?php

namespace App\Http\Resources;

use App\Models\Ballot;
use Illuminate\Http\Request;

/**
 * A ballot as docs/api/ballots/GET-elections-{election}-ballots.md shows it. No election, no
 * institution, no numeric key; `result_note` is for the results slices.
 *
 * @property Ballot $resource
 */
final class BallotResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $ballot = $this->resource;

        return [
            'title' => $ballot->title,
            'description' => $ballot->description,
            'position' => $ballot->position,
            'seats' => $ballot->seats,
            'allow_blank' => $ballot->allow_blank,
            // Nothing to count until slice 06c.
            'candidates_count' => 0,
            'created_at' => $ballot->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $ballot->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

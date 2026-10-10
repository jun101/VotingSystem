<?php

namespace App\Http\Resources;

use App\Models\Candidate;
use Illuminate\Http\Request;

/**
 * A candidate as docs/api/candidates/POST-ballots-{ballot}-candidates.md shows it. The ballot
 * and the party are named by their UUID; no numeric key, no file name.
 *
 * @property Candidate $resource
 */
final class CandidateResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $candidate = $this->resource;

        return [
            'ballot' => $candidate->ballot?->uuid,
            'party' => $candidate->party?->uuid,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'sex' => $candidate->sex->value,
            'slogan' => $candidate->slogan,
            'biography' => $candidate->biography,
            // The photo comes in slice 06d.
            'photo' => null,
            'position' => $candidate->position,
            'created_at' => $candidate->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $candidate->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

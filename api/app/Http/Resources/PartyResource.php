<?php

namespace App\Http\Resources;

use App\Models\Party;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;

/**
 * A party as docs/api/parties/GET-elections-{election}-parties.md shows it. No election, no
 * institution, no numeric key, no comparison key and no file name.
 *
 * @property Party $resource
 */
final class PartyResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $party = $this->resource;

        return [
            'name' => $party->name,
            'acronym' => $party->acronym,
            'colour' => $party->colour,
            'logo' => $party->logo_file === null ? null : [
                'sm' => '/media/'.ImageReEncoder::nameOf($party->logo_file, 96),
                'md' => '/media/'.ImageReEncoder::nameOf($party->logo_file, 192),
            ],
            // Nothing to count until slice 06c.
            'candidates_count' => 0,
            'created_at' => $party->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'updated_at' => $party->updated_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Election;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;

/**
 * An election as docs/api/elections/GET-elections-{election}.md shows it. Never a numeric key,
 * never the link to a first round, never the name of a file other than the cover's UUID.
 *
 * @property Election $resource
 */
final class ElectionResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $election = $this->resource;
        $cover = $election->cover_file;

        return [
            'title' => $election->title,
            'description' => $election->description,
            'status' => $election->status->value,
            'starts_at' => $election->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'ends_at' => $election->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
            'timezone' => $election->timezone,
            'language' => $election->language,
            'candidate_order' => $election->candidate_order->value,
            'results_display' => $election->results_display->value,
            'cover' => $cover === null ? null : [
                'sm' => '/media/'.ImageReEncoder::nameOf($cover, 480),
                'md' => '/media/'.ImageReEncoder::nameOf($cover, 960),
            ],
            // The list loads the count with the elections; any other read counts them here.
            'ballots_count' => $election->getAttribute('ballots_count') ?? $election->ballots()->count(),
            // Nothing to count until slice 07.
            'voters_count' => 0,
            'created_at' => $election->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
        ];
    }
}

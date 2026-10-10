<?php

namespace App\Http\Resources;

use App\Models\VoterGroup;
use Illuminate\Http\Request;

/**
 * A group as a voter shows it: its UUID as `id` and its name.
 *
 * @property VoterGroup $resource
 */
final class VoterGroupRefResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        return ['name' => $this->resource->name];
    }
}

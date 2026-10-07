<?php

namespace App\Http\Resources;

use App\Models\Institution;
use Illuminate\Http\Request;

/**
 * An institution as seen by its own users.
 *
 * @property Institution $resource
 */
final class InstitutionResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        return [
            'name' => $this->resource->name,
            'type' => $this->resource->type->value,
        ];
    }
}

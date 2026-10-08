<?php

namespace App\Http\Resources;

use App\Models\Institution;
use App\Support\Media\ImageReEncoder;
use Illuminate\Http\Request;

/**
 * The full profile of an institution, for its own users (docs/api/institution/GET-institution.md).
 * The short `InstitutionResource` of `GET /auth/me` is unchanged. Never the suspension date,
 * never a numeric key; the logo is three addresses made of its UUID, never a file name of the
 * upload.
 *
 * @property Institution $resource
 */
final class InstitutionProfileResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $institution = $this->resource;
        $logo = $institution->logo_file;

        return [
            'name' => $institution->name,
            'type' => $institution->type->value,
            'description' => $institution->description,
            'address' => $institution->address,
            'city' => $institution->city,
            'phone' => $institution->phone,
            'contact_email' => $institution->contact_email,
            'timezone' => $institution->timezone,
            'language' => $institution->language,
            'logo' => $logo === null ? null : [
                'sm' => '/media/'.ImageReEncoder::nameOf($logo, 64),
                'md' => '/media/'.ImageReEncoder::nameOf($logo, 160),
                'lg' => '/media/'.ImageReEncoder::nameOf($logo, 480),
            ],
        ];
    }
}

<?php

namespace App\Http\Resources;

use App\Models\Institution;
use Illuminate\Http\Request;

/**
 * An institution as the platform admin sees it (docs/api/platform/GET-platform-institutions.md): its status and
 * counts, nothing a person typed beyond the institution's own name and the suspension reason. The counts come
 * from `PlatformInstitutionCounts` and are carried in the unsaved attributes `platform_counts` and
 * `platform_last_sign_in`.
 *
 * @property Institution $resource
 */
final class PlatformInstitutionResource extends ApiResource
{
    /** @return array<string, mixed> */
    protected function fields(Request $request): array
    {
        $institution = $this->resource;
        $counts = $institution->getAttribute('platform_counts');
        $last = $institution->getAttribute('platform_last_sign_in');

        return [
            'name' => $institution->name,
            'type' => $institution->type->value,
            'status' => $institution->suspended_at === null ? 'active' : 'suspended',
            'suspended_at' => $institution->suspended_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'suspension_reason' => $institution->suspended_at === null ? null : $institution->suspension_reason,
            'created_at' => $institution->created_at?->utc()->format('Y-m-d\TH:i:s\Z'),
            'last_sign_in_at' => is_string($last) ? $last : null,
            'counts' => is_array($counts) ? $counts : ['users' => 0, 'voters' => 0, 'elections' => []],
        ];
    }
}

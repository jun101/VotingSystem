<?php

namespace App\Support\Publication;

use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\Institution;
use Illuminate\Support\Str;

/**
 * Finds the election a public page names: published, of an institution that is not suspended. An unknown
 * election, a value that is not a UUID, any other status and a suspended institution all answer the same 404.
 *
 * Stateless: the election is read again on every request and never kept (Octane).
 */
final class PublishedElections
{
    /** @throws ApiException 404 `not_found` */
    public function find(string $uuid): Election
    {
        if (! Str::isUuid($uuid)) {
            throw new ApiException(404, 'not_found');
        }

        // Nobody is signed in on a public page: the tenant scope would match nothing. The election is named by
        // its uuid (random, never listed) and only a published one is returned; the institution is checked below.
        $election = Election::withoutInstitutionScope()
            ->where('uuid', $uuid)
            ->where('status', ElectionStatus::Published->value)
            ->first();

        if ($election === null) {
            throw new ApiException(404, 'not_found');
        }

        // The institution is the tenant itself: it is not a scoped model.
        $suspended = Institution::query()->whereKey($election->institution_id)->whereNotNull('suspended_at')->exists();

        if ($suspended) {
            throw new ApiException(404, 'not_found');
        }

        return $election;
    }
}

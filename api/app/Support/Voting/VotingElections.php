<?php

namespace App\Support\Voting;

use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\Institution;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

/**
 * Finds the election a voter's address names, and says whether it can be voted in now.
 *
 * Stateless: the election is read again on every request and never kept (Octane).
 */
final class VotingElections
{
    /**
     * An election that is not a draft, by its uuid. A draft, an unknown election and a value that
     * is not a UUID all answer the same 404.
     *
     * @throws ApiException 404 `not_found`
     */
    public function find(string $uuid): Election
    {
        if (! Str::isUuid($uuid)) {
            throw new ApiException(404, 'not_found');
        }

        // A voter has no institution user: the tenant scope would match nothing. The election is named by its
        // uuid (random, never listed) and a draft is refused, so the scope's job is done by these two.
        $election = Election::withoutInstitutionScope()
            ->where('uuid', $uuid)
            ->where('status', '!=', ElectionStatus::Draft->value)
            ->first();

        return $election ?? throw new ApiException(404, 'not_found');
    }

    /**
     * `before`, `open`, `closed` or `paused`, from the status and the server's clock. A suspended
     * institution's scheduled or open election is paused.
     *
     * @return 'before'|'open'|'closed'|'paused'
     */
    public function phase(Election $election, ?Carbon $now = null): string
    {
        return VotePhase::of($election->status, $election->starts_at, $election->ends_at, $now ?? Carbon::now('UTC'), $this->suspended($election));
    }

    public function suspended(Election $election): bool
    {
        // The institution is the tenant itself: it is not a scoped model.
        return Institution::query()->whereKey($election->institution_id)->whereNotNull('suspended_at')->exists();
    }
}

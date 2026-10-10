<?php

namespace App\Support\Voters;

use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\VoterGroup;

/**
 * The group a voter's `group` name points to, found in the election (case and surrounding
 * spaces ignored, accents counted) or created. Called inside the transaction that holds the
 * election's row lock, so two requests never create the same group or pass the limit together.
 */
final class GroupByName
{
    /** An election holds at most this many groups. */
    public const LIMIT = 100;

    /**
     * @param  Election  $locked  the election row, read under its lock
     * @param  string|null  $name  already trimmed; null or blank is no group
     *
     * @throws ApiException 409 `group_limit_reached`, or `election_voters_locked` when the group
     *                      would have to be created in an open election
     */
    public function __invoke(Election $locked, ?string $name): ?VoterGroup
    {
        if ($name === null || trim($name) === '') {
            return null;
        }

        $found = VoterGroup::query()
            ->where('election_id', $locked->getKey())
            ->where('name_key', VoterGroup::keyOf($name))
            ->first();

        if ($found !== null) {
            return $found;
        }

        // Groups are added in a draft or scheduled election only: an open one keeps the ballots' groups.
        $locked->assertVotersDeletable();

        if (VoterGroup::query()->where('election_id', $locked->getKey())->count() >= self::LIMIT) {
            throw new ApiException(409, 'group_limit_reached');
        }

        $group = new VoterGroup(['name' => $name]);
        $group->election_id = $locked->id;
        $group->save();

        return $group;
    }
}

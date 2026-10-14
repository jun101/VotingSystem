<?php

namespace App\Support\Voters;

use App\Models\Voter;
use App\Models\VoterGroup;
use Illuminate\Database\Eloquent\Builder;

/**
 * The filters the voters list and the voters export share (`q`, `group`, `voted`). Columns are
 * qualified: the export joins the groups.
 */
final class VoterFilters
{
    /**
     * @param  Builder<Voter>  $query  a query on `voters`, already limited to one election
     * @param  int  $electionId  the election of the query (a group of another election keeps nothing)
     * @param  string|null  $group  `none`, a group UUID, or null
     * @param  string|null  $voted  `yes`, `no`, or null
     */
    public static function apply(Builder $query, int $electionId, ?string $search, ?string $group, ?string $voted): void
    {
        if ($search !== null) {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $any) use ($like): void {
                $any->where('voters.full_name', 'like', $like)
                    ->orWhere('voters.identifier', 'like', $like)
                    ->orWhere('voters.email', 'like', $like);
            });
        }

        if ($group === 'none') {
            $query->whereNull('voters.voter_group_id');
        } elseif ($group !== null) {
            // A UUID that names no group of this election keeps nothing.
            $query->whereIn('voters.voter_group_id', VoterGroup::query()
                ->where('election_id', $electionId)
                ->where('uuid', $group)
                ->select('id'));
        }

        if ($voted === 'yes') {
            $query->whereHas('participation');
        } elseif ($voted === 'no') {
            $query->whereDoesntHave('participation');
        }
    }
}

<?php

namespace App\Http\Requests\Groups;

use App\Models\VoterGroup;

/** docs/api/groups/PATCH-groups-{group}.md: the rules of the creation; the group's own name is no duplicate. */
class UpdateGroupRequest extends CreateGroupRequest
{
    protected function electionKey(): mixed
    {
        $group = $this->route('group');

        return $group instanceof VoterGroup ? $group->election_id : null;
    }
}

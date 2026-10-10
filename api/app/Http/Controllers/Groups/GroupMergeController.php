<?php

namespace App\Http\Controllers\Groups;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Groups\MergeGroupsRequest;
use App\Http\Resources\GroupResource;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GroupMergeController extends Controller
{
    /**
     * Merge a group into another.
     *
     * Every voter of the group moves to `into`, another group of the same election, and the
     * group is deleted: one transaction. A draft or scheduled election only. Answers the
     * receiving group. Owner or manager. Limited to 120 requests per hour per user.
     */
    #[Response(status: 200, type: 'array{data: array{id: string, name: string, voters_count: int, created_at: string, updated_at: string}}')]
    public function __invoke(MergeGroupsRequest $request, VoterGroup $group): GroupResource
    {

        if (! Gate::forUser(request()->user())->allows('update', $group)) {
            throw new ApiException(403, 'forbidden');
        }

        $election = $group->election ?? throw new ApiException(404, 'not_found');
        $election->assertVotersDeletable();

        $into = $request->into();

        $receiving = DB::transaction(function () use ($election, $group, $into): VoterGroup {
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertVotersDeletable();

            // Both rows are locked in one fixed order (by key), so two merges cannot wait on each other.
            $target = VoterGroup::query()->where('election_id', $locked->getKey())->where('uuid', $into)->first();
            $keys = array_filter([$group->id, $target?->id]);
            $rows = VoterGroup::query()->whereIn('id', $keys)->orderBy('id')->lockForUpdate()->get()->keyBy(fn (VoterGroup $row): int => $row->id);

            $source = $rows->get($group->id);

            if (! $source instanceof VoterGroup) {
                throw new ApiException(404, 'not_found');
            }

            $receiver = $target === null ? null : $rows->get($target->id);

            if (! $receiver instanceof VoterGroup) {
                // The receiving group was deleted since the request was checked.
                throw ValidationException::withMessages(['into' => ['invalid']]);
            }

            Voter::query()->where('voter_group_id', $source->id)->update(['voter_group_id' => $receiver->id]);
            $source->delete();

            return $receiver;
        });

        Log::info('group.merge', ['outcome' => 'merged']);

        return new GroupResource($receiving);
    }
}

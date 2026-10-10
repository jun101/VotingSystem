<?php

namespace App\Http\Controllers\Groups;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Groups\CreateGroupRequest;
use App\Http\Requests\Groups\ListGroupsRequest;
use App\Http\Requests\Groups\UpdateGroupRequest;
use App\Http\Resources\GroupResource;
use App\Http\Resources\PageOf;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Voters\GroupByName;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class GroupController extends Controller
{
    /**
     * List the groups of an election.
     *
     * By name, ignoring case, then creation order, with their voter counts, in any status of the
     * election. An election holds at most 100, so `per_page=100` returns them all. `meta` also
     * carries `voters_total` and `ungrouped`. Owner or manager.
     *
     * @response array{data: list<array{id: string, name: string, voters_count: int, created_at: string, updated_at: string}>, meta: array{page: int, per_page: int, total: int, voters_total: int, ungrouped: int}}
     */
    public function index(ListGroupsRequest $request, Election $election): PageOf
    {
        $this->allow('viewAny', [VoterGroup::class, $election]);

        $page = $election->voterGroups()->withCount('voters')->paginate($request->perPage(), page: $request->page());

        $voters = Voter::query()->where('election_id', $election->getKey());

        return PageOf::from($page, GroupResource::class)->withMeta([
            'voters_total' => (clone $voters)->count(),
            'ungrouped' => (clone $voters)->whereNull('voter_group_id')->count(),
        ]);
    }

    /**
     * Add an empty group.
     *
     * To a draft or scheduled election, at most 100 per election (409 `group_limit_reached`).
     * The name is unique in the election, ignoring case and surrounding spaces. Owner or
     * manager. Limited to 120 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{id: string, name: string, voters_count: int, created_at: string, updated_at: string}}')]
    public function store(CreateGroupRequest $request, Election $election): JsonResponse
    {
        $this->allow('create', [VoterGroup::class, $election]);

        $election->assertVotersDeletable();

        try {
            $group = DB::transaction(function () use ($request, $election): VoterGroup {
                $locked = $this->lockElection($election);

                if (VoterGroup::query()->where('election_id', $locked->getKey())->count() >= GroupByName::LIMIT) {
                    throw new ApiException(409, 'group_limit_reached');
                }

                $group = new VoterGroup(['name' => $request->name()]);
                $group->election_id = $locked->id;
                $group->save();

                return $group;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same name at once: the index let one in.
            throw ValidationException::withMessages(['name' => ['unique']]);
        }

        Log::info('group.create', ['outcome' => 'created']);

        return (new GroupResource($group))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/groups/'.$group->uuid);
    }

    /**
     * Rename a group.
     *
     * A draft or scheduled election only; its voters follow it. Owner or manager. Limited to
     * 120 requests per hour per user.
     *
     * @response array{data: array{id: string, name: string, voters_count: int, created_at: string, updated_at: string}}
     */
    public function update(UpdateGroupRequest $request, VoterGroup $group): GroupResource
    {
        $this->allow('update', $group);

        $this->electionOf($group)->assertVotersDeletable();

        try {
            $saved = DB::transaction(function () use ($request, $group): VoterGroup {
                $this->lockElection($this->electionOf($group));

                $row = VoterGroup::query()->whereKey($group->getKey())->lockForUpdate()->first();

                if ($row === null) {
                    throw new ApiException(404, 'not_found');
                }

                $row->fill(['name' => $request->name()])->save();

                return $row;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['unique']]);
        }

        Log::info('group.update', ['outcome' => 'updated']);

        return new GroupResource($saved);
    }

    /**
     * Delete a group.
     *
     * A draft or scheduled election only, and only a group with no voter (409 `group_in_use`).
     * Final: there is no trash. Owner or manager. Limited to 120 requests per hour per user.
     */
    public function destroy(VoterGroup $group): HttpResponse
    {
        $this->allow('delete', $group);

        $this->electionOf($group)->assertVotersDeletable();

        DB::transaction(function () use ($group): void {
            // Voters are added and moved under the same lock, so the count below holds until commit.
            $this->lockElection($this->electionOf($group));

            $row = VoterGroup::query()->whereKey($group->getKey())->lockForUpdate()->first();

            if ($row === null) {
                throw new ApiException(404, 'not_found');
            }

            if (Voter::query()->where('voter_group_id', $row->getKey())->exists()) {
                throw new ApiException(409, 'group_in_use');
            }

            $row->delete();
        });

        Log::info('group.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    private function electionOf(VoterGroup $group): Election
    {
        return $group->election ?? throw new ApiException(404, 'not_found');
    }

    /**
     * The election row, read again under its lock, so a change that landed meanwhile counts.
     *
     * @throws ApiException 404, or 409 `election_voters_locked`
     */
    private function lockElection(Election $election): Election
    {
        $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        $locked->assertVotersDeletable();

        return $locked;
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     *
     * @param  array{0: class-string<VoterGroup>, 1: Election}|VoterGroup  $subject
     */
    private function allow(string $ability, array|VoterGroup $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

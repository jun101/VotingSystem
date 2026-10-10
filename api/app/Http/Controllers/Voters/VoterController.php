<?php

namespace App\Http\Controllers\Voters;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Voters\CreateVoterRequest;
use App\Http\Requests\Voters\ListVotersRequest;
use App\Http\Requests\Voters\UpdateVoterRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\VoterResource;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Voters\GroupByName;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class VoterController extends Controller
{
    /** An election holds at most this many voters. */
    private const LIMIT = 10000;

    /**
     * List the voters of an election.
     *
     * 24 per page by default, by full name ignoring case, then creation order, in any status of
     * the election. `q` looks for a text in the full name, the identifier and the email; `group`
     * is a group's UUID or `none`. Owner or manager.
     *
     * @response array{data: list<array{id: string, full_name: string, group: array{id: string, name: string}|null, identifier: string|null, email: string|null, phone: string|null, created_at: string, updated_at: string}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListVotersRequest $request, Election $election): PageOf
    {
        $this->allow('viewAny', [Voter::class, $election]);

        $query = $election->voters()->with('group');

        $search = $request->search();

        if ($search !== null) {
            $like = '%'.addcslashes($search, '\\%_').'%';

            $query->where(function (Builder $any) use ($like): void {
                $any->where('full_name', 'like', $like)
                    ->orWhere('identifier', 'like', $like)
                    ->orWhere('email', 'like', $like);
            });
        }

        $group = $request->group();

        if ($group === 'none') {
            $query->whereNull('voter_group_id');
        } elseif ($group !== null) {
            // A UUID that names no group of this election keeps nothing.
            $query->whereIn('voter_group_id', VoterGroup::query()
                ->where('election_id', $election->getKey())
                ->where('uuid', $group)
                ->select('id'));
        }

        return PageOf::from($query->paginate($request->perPage(), page: $request->page()), VoterResource::class);
    }

    /**
     * Add a voter.
     *
     * To a draft, scheduled or open election, at most 10 000 per election (409
     * `voter_limit_reached`). `group` is a group's name: the group is found, or created
     * (409 `group_limit_reached` at 100). The identifier and the email are unique in the election.
     * Owner or manager. Limited to 240 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{id: string, full_name: string, group: array{id: string, name: string}|null, identifier: string|null, email: string|null, phone: string|null, created_at: string, updated_at: string}}')]
    public function store(CreateVoterRequest $request, Election $election, GroupByName $groups): JsonResponse
    {
        $this->allow('create', [Voter::class, $election]);

        $election->assertVotersEditable();

        try {
            $voter = DB::transaction(function () use ($request, $election, $groups): Voter {
                $locked = $this->lockElection($election);

                if (Voter::query()->where('election_id', $locked->getKey())->count() >= self::LIMIT) {
                    throw new ApiException(409, 'voter_limit_reached');
                }

                $group = $groups($locked, $request->groupName());

                $voter = new Voter($request->attributesToWrite());
                $voter->election_id = $locked->id;
                $voter->voter_group_id = $group?->id;
                $voter->save();
                $voter->setRelation('group', $group);

                return $voter;
            });
        } catch (UniqueConstraintViolationException $e) {
            // Two requests with the same value at once: the index let one in.
            throw $this->duplicate($e);
        }

        Log::info('voter.create', ['outcome' => 'created']);

        return (new VoterResource($voter))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/voters/'.$voter->uuid);
    }

    /**
     * Change a voter.
     *
     * Every field is optional; only those sent change. `null` or blank clears the group, the
     * identifier, the email and the phone. A draft, scheduled or open election. Owner or manager.
     * Limited to 240 requests per hour per user.
     *
     * @response array{data: array{id: string, full_name: string, group: array{id: string, name: string}|null, identifier: string|null, email: string|null, phone: string|null, created_at: string, updated_at: string}}
     */
    public function update(UpdateVoterRequest $request, Voter $voter, GroupByName $groups): VoterResource
    {
        $this->allow('update', $voter);

        $this->electionOf($voter)->assertVotersEditable();

        try {
            $saved = DB::transaction(function () use ($request, $voter, $groups): Voter {
                $locked = $this->lockElection($this->electionOf($voter));

                $row = Voter::query()->whereKey($voter->getKey())->lockForUpdate()->first();

                if ($row === null) {
                    throw new ApiException(404, 'not_found');
                }

                $row->fill($request->attributesToWrite());

                if ($request->hasGroup()) {
                    $row->voter_group_id = $groups($locked, $request->groupName())?->id;
                }

                $row->save();
                $row->load('group');

                return $row;
            });
        } catch (UniqueConstraintViolationException $e) {
            throw $this->duplicate($e);
        }

        Log::info('voter.update', ['outcome' => 'updated']);

        return new VoterResource($saved);
    }

    /**
     * Delete a voter.
     *
     * A draft or scheduled election only: slice 12 knows who has voted in an open one. Final:
     * there is no trash. The voter's group stays. Owner or manager. Limited to 240 requests per
     * hour per user.
     */
    public function destroy(Voter $voter): HttpResponse
    {
        $this->allow('delete', $voter);

        $this->electionOf($voter)->assertVotersDeletable();

        DB::transaction(function () use ($voter): void {
            $locked = $this->lockElection($this->electionOf($voter), editable: false);
            $locked->assertVotersDeletable();

            $row = Voter::query()->whereKey($voter->getKey())->lockForUpdate()->first();

            if ($row === null) {
                throw new ApiException(404, 'not_found');
            }

            $row->delete();
        });

        Log::info('voter.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    private function electionOf(Voter $voter): Election
    {
        return $voter->election ?? throw new ApiException(404, 'not_found');
    }

    /**
     * The election row, read again under its lock, so a change that landed meanwhile counts.
     *
     * @param  bool  $editable  judge whether voters may still be added and edited
     *
     * @throws ApiException 404, or 409 `election_voters_locked`
     */
    private function lockElection(Election $election, bool $editable = true): Election
    {
        $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        if ($editable) {
            $locked->assertVotersEditable();
        }

        return $locked;
    }

    /** The 422 of a unique index hit: the field is the one the index names. */
    private function duplicate(UniqueConstraintViolationException $e): ValidationException
    {
        $field = str_contains($e->getMessage(), 'email') ? 'email' : 'identifier';

        return ValidationException::withMessages([$field => ['unique']]);
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     *
     * @param  array{0: class-string<Voter>, 1: Election}|Voter  $subject
     */
    private function allow(string $ability, array|Voter $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

<?php

namespace App\Http\Controllers\Ballots;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Ballots\CreateBallotRequest;
use App\Http\Requests\Ballots\ListBallotsRequest;
use App\Http\Requests\Ballots\ReorderBallotsRequest;
use App\Http\Requests\Ballots\UpdateBallotRequest;
use App\Http\Resources\BallotResource;
use App\Http\Resources\PageOf;
use App\Models\Ballot;
use App\Models\Election;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class BallotController extends Controller
{
    /** An election holds at most this many ballots. */
    private const LIMIT = 50;

    /**
     * List the ballots of an election.
     *
     * In display order (position, then creation), in any status of the election. An election
     * holds at most 50, so `per_page=100` returns them all. Owner or manager.
     *
     * @response array{data: list<array{id: string, title: string, description: string|null, position: int, seats: int, allow_blank: bool, candidates_count: int, created_at: string, updated_at: string}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListBallotsRequest $request, Election $election): PageOf
    {
        $this->allow('viewAny', [Ballot::class, $election]);

        return $this->pageOf($election, $request->perPage(), $request->page());
    }

    /**
     * Add a ballot.
     *
     * At the end of a draft election, at most 50 per election (409 `ballot_limit_reached`).
     * Owner or manager. Limited to 120 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{id: string, title: string, description: string|null, position: int, seats: int, allow_blank: bool, candidates_count: int, created_at: string, updated_at: string}}')]
    public function store(CreateBallotRequest $request, Election $election): JsonResponse
    {
        $this->allow('create', [Ballot::class, $election]);

        $election->assertEditable();

        $ballot = DB::transaction(function () use ($request, $election): Ballot {
            $locked = $this->lockElection($election);

            if ($locked->ballots()->count() >= self::LIMIT) {
                throw new ApiException(409, 'ballot_limit_reached');
            }

            $ballot = new Ballot($request->attributesToWrite());
            $ballot->election_id = $locked->id;
            $last = $locked->ballots()->max('position');
            $ballot->position = (is_numeric($last) ? (int) $last : 0) + 1;
            $ballot->save();

            return $ballot;
        });

        Log::info('ballot.create', ['outcome' => 'created']);

        return (new BallotResource($ballot))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/ballots/'.$ballot->uuid);
    }

    /**
     * Change a ballot.
     *
     * Every field is optional; only those sent change. Not the position (see the order
     * endpoint). A draft election only. Owner or manager. Limited to 120 requests per hour per user.
     *
     * @response array{data: array{id: string, title: string, description: string|null, position: int, seats: int, allow_blank: bool, candidates_count: int, created_at: string, updated_at: string}}
     */
    public function update(UpdateBallotRequest $request, Ballot $ballot): BallotResource
    {
        $this->allow('update', $ballot);

        $this->electionOf($ballot)->assertEditable();

        $saved = DB::transaction(function () use ($request, $ballot): Ballot {
            $this->lockElection($this->electionOf($ballot));

            $locked = Ballot::query()->whereKey($ballot->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->fill($request->attributesToWrite())->save();

            return $locked;
        });

        Log::info('ballot.update', ['outcome' => 'updated']);

        return new BallotResource($saved);
    }

    /**
     * Delete a ballot.
     *
     * A draft election only. The positions after it close the gap. Owner or manager. Limited
     * to 120 requests per hour per user.
     */
    public function destroy(Ballot $ballot): HttpResponse
    {
        $this->allow('delete', $ballot);

        $this->electionOf($ballot)->assertEditable();

        DB::transaction(function () use ($ballot): void {
            $this->lockElection($this->electionOf($ballot));

            $locked = Ballot::query()->whereKey($ballot->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $election = $locked->election_id;
            $position = $locked->position;
            $locked->delete();

            Ballot::query()
                ->where('election_id', $election)
                ->where('position', '>', $position)
                ->decrement('position');
        });

        Log::info('ballot.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    /**
     * Put the ballots in order.
     *
     * `ballots` must be exactly the UUIDs of the election's ballots, each once, in the wanted
     * order (422 `ballots: set_mismatch` otherwise). A draft election only. Answers the list,
     * in the new order. Owner or manager. Limited to 240 requests per hour per user.
     *
     * @response array{data: list<array{id: string, title: string, description: string|null, position: int, seats: int, allow_blank: bool, candidates_count: int, created_at: string, updated_at: string}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function reorder(ReorderBallotsRequest $request, Election $election): PageOf
    {
        $this->allow('reorder', [Ballot::class, $election]);

        $election->assertEditable();

        $wanted = $request->order();

        DB::transaction(function () use ($election, $wanted): void {
            $locked = $this->lockElection($election);

            $ballots = Ballot::query()->where('election_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $stored = $ballots->map(fn (Ballot $ballot): string => $ballot->uuid)->all();

            // The same message for an unknown UUID, another election's and another institution's.
            if (count($wanted) !== count($stored) || array_diff($wanted, $stored) !== [] || count(array_unique($wanted)) !== count($wanted)) {
                throw ValidationException::withMessages(['ballots' => ['set_mismatch']]);
            }

            $byUuid = $ballots->keyBy('uuid');

            foreach ($wanted as $index => $uuid) {
                $ballot = $byUuid->get($uuid);

                if ($ballot instanceof Ballot && $ballot->position !== $index + 1) {
                    $ballot->position = $index + 1;
                    $ballot->save();
                }
            }
        });

        Log::info('ballot.reorder', ['outcome' => 'reordered']);

        return $this->pageOf($election, 100, 1);
    }

    private function electionOf(Ballot $ballot): Election
    {
        return $ballot->election ?? throw new ApiException(404, 'not_found');
    }

    private function pageOf(Election $election, int $perPage, int $page): PageOf
    {
        return PageOf::from($election->ballots()->paginate($perPage, page: $page), BallotResource::class);
    }

    /**
     * The election row, read again under its lock, so a change that landed meanwhile counts.
     *
     * @throws ApiException 404, or 409 `election_not_editable`
     */
    private function lockElection(Election $election): Election
    {
        $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        $locked->assertEditable();

        return $locked;
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     *
     * @param  array{0: class-string<Ballot>, 1: Election}|Ballot  $subject
     */
    private function allow(string $ability, array|Ballot $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

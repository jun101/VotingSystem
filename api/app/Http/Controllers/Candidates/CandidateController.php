<?php

namespace App\Http\Controllers\Candidates;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Candidates\CreateCandidateRequest;
use App\Http\Requests\Candidates\ReorderCandidatesRequest;
use App\Http\Requests\Candidates\UpdateCandidateRequest;
use App\Http\Resources\CandidateResource;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Party;
use App\Support\Media\ImageReEncoder;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class CandidateController extends Controller
{
    /** A ballot holds at most this many candidates. */
    private const LIMIT = 50;

    /**
     * Add a candidate.
     *
     * At the end of a ballot of a draft election, at most 50 per ballot (409
     * `candidate_limit_reached`). The party, when given, is one of the same election. Owner or
     * manager. Limited to 120 requests per hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, ballot: string, party: string|null, first_name: string, last_name: string, sex: 'male'|'female', slogan: string|null, biography: string|null, photo: array{sm: string, md: string}|null, position: int, created_at: string, updated_at: string}}")]
    public function store(CreateCandidateRequest $request, Ballot $ballot): JsonResponse
    {
        $this->allow('addCandidate', $ballot);

        $this->electionOf($ballot)->assertEditable();

        $candidate = DB::transaction(function () use ($request, $ballot): Candidate {
            $election = $this->lockElection($this->electionOf($ballot));
            $locked = $this->lockBallot($ballot);

            if ($this->countIn($locked) >= self::LIMIT) {
                throw new ApiException(409, 'candidate_limit_reached');
            }

            $candidate = new Candidate($request->attributesToWrite());
            $candidate->election_id = $election->id;
            $candidate->ballot_id = $locked->id;
            $party = $this->lockParty($request, $election);
            $candidate->party_id = $party?->id;
            $candidate->position = $this->lastPositionIn($locked) + 1;
            $candidate->save();

            return $candidate->setRelation('ballot', $locked)->setRelation('party', $party);
        });

        Log::info('candidate.create', ['outcome' => 'created']);

        return (new CandidateResource($candidate))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/candidates/'.$candidate->uuid);
    }

    /**
     * Change a candidate.
     *
     * Every field is optional; only those sent change. `ballot` moves it to the end of another
     * ballot of the same election. Not the position (see the order endpoint) nor the photo. A
     * draft election only. Owner or manager. Limited to 120 requests per hour per user.
     *
     * @response array{data: array{id: string, ballot: string, party: string|null, first_name: string, last_name: string, sex: 'male'|'female', slogan: string|null, biography: string|null, photo: array{sm: string, md: string}|null, position: int, created_at: string, updated_at: string}}
     */
    public function update(UpdateCandidateRequest $request, Candidate $candidate): CandidateResource
    {
        $this->allow('update', $candidate);

        $election = $this->electionOf($candidate);
        $election->assertEditable();

        $saved = DB::transaction(function () use ($request, $candidate, $election): Candidate {
            $lockedElection = $this->lockElection($election);

            // The party and the ballot named are read again by UUID, now that the election is locked:
            // one deleted meanwhile answers the same 422 as an unknown one.
            $party = $this->lockParty($request, $lockedElection);
            $target = $this->findTarget($request, $lockedElection);

            $locked = $this->lockCandidate($candidate);

            if ($target !== null && $target->id !== $locked->ballot_id) {
                $this->moveTo($locked, $target);
            }

            $locked->fill($request->attributesToWrite());

            if ($request->namesParty()) {
                $locked->party_id = $party?->id;
            }

            $locked->save();

            return $locked->unsetRelation('ballot')->unsetRelation('party');
        });

        Log::info('candidate.update', ['outcome' => 'updated']);

        return new CandidateResource($saved->load('ballot', 'party'));
    }

    /**
     * Delete a candidate.
     *
     * A draft election only. The positions after it close the gap. Owner or manager. Limited to
     * 120 requests per hour per user.
     */
    public function destroy(Candidate $candidate, ImageReEncoder $images): HttpResponse
    {
        $this->allow('delete', $candidate);

        $election = $this->electionOf($candidate);
        $election->assertEditable();

        $photo = null;

        DB::transaction(function () use ($candidate, $election, &$photo): void {
            $this->lockElection($election);

            $locked = $this->lockCandidate($candidate);

            $photo = $locked->photo_file;
            $ballot = $locked->ballot_id;
            $position = $locked->position;
            $locked->delete();

            Candidate::query()
                ->where('ballot_id', $ballot)
                ->where('position', '>', $position)
                ->decrement('position');
        });

        // The photo files go after the commit.
        $images->delete($photo, ImageReEncoder::CANDIDATE_PHOTO_SIZES);

        Log::info('candidate.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    /**
     * Put the candidates of a ballot in order.
     *
     * `candidates` must be exactly the UUIDs of the ballot's candidates, each once, in the
     * wanted order (422 `candidates: set_mismatch` otherwise). A draft election only. Answers
     * the candidates, in the new order. Owner or manager. Limited to 240 requests per hour per user.
     *
     * @response array{data: list<array{id: string, ballot: string, party: string|null, first_name: string, last_name: string, sex: 'male'|'female', slogan: string|null, biography: string|null, photo: array{sm: string, md: string}|null, position: int, created_at: string, updated_at: string}>}
     */
    public function reorder(ReorderCandidatesRequest $request, Ballot $ballot): AnonymousResourceCollection
    {
        $this->allow('reorderCandidates', $ballot);

        $election = $this->electionOf($ballot);

        // Order of the answers: record 404, body 422, state 409. The state is judged after the set.
        $wanted = $request->order();

        DB::transaction(function () use ($ballot, $election, $wanted): void {
            $lockedElection = $this->lockElection($election, judge: false);
            $locked = $this->lockBallot($ballot);

            $candidates = Candidate::query()->where('ballot_id', $locked->id)->orderBy('id')->lockForUpdate()->get();
            $stored = $candidates->map(fn (Candidate $candidate): string => $candidate->uuid)->all();

            // The same message for an unknown UUID, another ballot's and another institution's.
            if (count($wanted) !== count($stored) || array_diff($wanted, $stored) !== [] || count(array_unique($wanted)) !== count($wanted)) {
                throw ValidationException::withMessages(['candidates' => ['set_mismatch']]);
            }

            // Judged under the lock, once the set is known to be right.
            $lockedElection->assertEditable();

            $byUuid = $candidates->keyBy('uuid');

            foreach ($wanted as $index => $uuid) {
                $candidate = $byUuid->get($uuid);

                if ($candidate instanceof Candidate && $candidate->position !== $index + 1) {
                    $candidate->position = $index + 1;
                    $candidate->save();
                }
            }
        });

        Log::info('candidate.reorder', ['outcome' => 'reordered']);

        return CandidateResource::collection($ballot->candidates()->with('party')->get());
    }

    /**
     * The party named in the body, read again by UUID under the election lock.
     *
     * @throws ValidationException 422 `party: invalid`
     */
    private function lockParty(CreateCandidateRequest $request, Election $election): ?Party
    {
        $named = $request->input('party');

        if (! is_string($named)) {
            return null;
        }

        return Party::query()
            ->where('election_id', $election->id)
            ->where('uuid', strtolower($named))
            ->lockForUpdate()
            ->first()
            ?? throw ValidationException::withMessages(['party' => ['invalid']]);
    }

    /**
     * The ballot a change moves the candidate to, read again by UUID under the election lock.
     *
     * @throws ValidationException 422 `ballot: invalid`
     */
    private function findTarget(CreateCandidateRequest $request, Election $election): ?Ballot
    {
        if ($request->targetBallot() === null) {
            return null;
        }

        $named = $request->input('ballot');

        return is_string($named)
            ? Ballot::query()->where('election_id', $election->id)->where('uuid', strtolower($named))->first()
                ?? throw ValidationException::withMessages(['ballot' => ['invalid']])
            : throw ValidationException::withMessages(['ballot' => ['invalid']]);
    }

    /** Puts the candidate last in another ballot of its election, and closes the gap it leaves. */
    private function moveTo(Candidate $candidate, Ballot $target): void
    {
        // Both ballots are locked, the lower id first, so two moves in opposite ways cannot wait for each other.
        $ids = [$candidate->ballot_id, $target->id];
        sort($ids);
        Ballot::query()->whereIn('id', $ids)->orderBy('id')->lockForUpdate()->get();

        $locked = $this->lockBallot($target);

        if ($this->countIn($locked) >= self::LIMIT) {
            throw new ApiException(409, 'candidate_limit_reached');
        }

        Candidate::query()
            ->where('ballot_id', $candidate->ballot_id)
            ->where('position', '>', $candidate->position)
            ->decrement('position');

        $candidate->ballot_id = $locked->id;
        $candidate->position = $this->lastPositionIn($locked) + 1;
    }

    private function countIn(Ballot $ballot): int
    {
        return Candidate::query()->where('ballot_id', $ballot->id)->count();
    }

    private function lastPositionIn(Ballot $ballot): int
    {
        $last = Candidate::query()->where('ballot_id', $ballot->id)->max('position');

        return is_numeric($last) ? (int) $last : 0;
    }

    private function electionOf(Ballot|Candidate $record): Election
    {
        return $record->election ?? throw new ApiException(404, 'not_found');
    }

    private function lockBallot(Ballot $ballot): Ballot
    {
        return Ballot::query()->whereKey($ballot->getKey())->lockForUpdate()->first()
            ?? throw new ApiException(404, 'not_found');
    }

    private function lockCandidate(Candidate $candidate): Candidate
    {
        return Candidate::query()->whereKey($candidate->getKey())->lockForUpdate()->first()
            ?? throw new ApiException(404, 'not_found');
    }

    /**
     * The election row, read again under its lock, so a change that landed meanwhile counts.
     *
     * @throws ApiException 404, or 409 `election_not_editable`
     */
    private function lockElection(Election $election, bool $judge = true): Election
    {
        $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        if ($judge) {
            $locked->assertEditable();
        }

        return $locked;
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     */
    private function allow(string $ability, Ballot|Candidate $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

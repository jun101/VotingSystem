<?php

namespace App\Http\Controllers\Parties;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Parties\CreatePartyRequest;
use App\Http\Requests\Parties\ListPartiesRequest;
use App\Http\Requests\Parties\UpdatePartyRequest;
use App\Http\Resources\PageOf;
use App\Http\Resources\PartyResource;
use App\Models\Election;
use App\Models\Party;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class PartyController extends Controller
{
    /** An election holds at most this many parties. */
    private const LIMIT = 30;

    /**
     * List the parties of an election.
     *
     * By name, ignoring case, then creation order, in any status of the election. An election
     * holds at most 30, so `per_page=100` returns them all. Owner or manager.
     *
     * @response array{data: list<array{id: string, name: string, acronym: string|null, colour: string, logo: null, candidates_count: int, created_at: string, updated_at: string}>, meta: array{page: int, per_page: int, total: int}}
     */
    public function index(ListPartiesRequest $request, Election $election): PageOf
    {
        $this->allow('viewAny', [Party::class, $election]);

        return PageOf::from($election->parties()->paginate($request->perPage(), page: $request->page()), PartyResource::class);
    }

    /**
     * Add a party.
     *
     * To a draft election, at most 30 per election (409 `party_limit_reached`). The name is
     * unique in the election, ignoring case. Owner or manager. Limited to 120 requests per hour per user.
     */
    #[Response(status: 201, type: 'array{data: array{id: string, name: string, acronym: string|null, colour: string, logo: null, candidates_count: int, created_at: string, updated_at: string}}')]
    public function store(CreatePartyRequest $request, Election $election): JsonResponse
    {
        $this->allow('create', [Party::class, $election]);

        $election->assertEditable();

        try {
            $party = DB::transaction(function () use ($request, $election): Party {
                $locked = $this->lockElection($election);

                if ($locked->parties()->count() >= self::LIMIT) {
                    throw new ApiException(409, 'party_limit_reached');
                }

                $party = new Party($request->attributesToWrite());
                $party->election_id = $locked->id;
                $party->save();

                return $party;
            });
        } catch (UniqueConstraintViolationException) {
            // Two requests with the same name at once: the index let one in.
            throw ValidationException::withMessages(['name' => ['unique']]);
        }

        Log::info('party.create', ['outcome' => 'created']);

        return (new PartyResource($party))->response()
            ->setStatusCode(201)
            ->header('Location', '/api/v1/parties/'.$party->uuid);
    }

    /**
     * Change a party.
     *
     * Every field is optional; only those sent change. A draft election only. Owner or
     * manager. Limited to 120 requests per hour per user.
     *
     * @response array{data: array{id: string, name: string, acronym: string|null, colour: string, logo: null, candidates_count: int, created_at: string, updated_at: string}}
     */
    public function update(UpdatePartyRequest $request, Party $party): PartyResource
    {
        $this->allow('update', $party);

        $this->electionOf($party)->assertEditable();

        try {
            $saved = DB::transaction(function () use ($request, $party): Party {
                $this->lockElection($this->electionOf($party));

                $locked = Party::query()->whereKey($party->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new ApiException(404, 'not_found');
                }

                $locked->fill($request->attributesToWrite())->save();

                return $locked;
            });
        } catch (UniqueConstraintViolationException) {
            throw ValidationException::withMessages(['name' => ['unique']]);
        }

        Log::info('party.update', ['outcome' => 'updated']);

        return new PartyResource($saved);
    }

    /**
     * Delete a party.
     *
     * A draft election only. Final: there is no trash. Owner or manager. Limited to 120
     * requests per hour per user.
     */
    public function destroy(Party $party): HttpResponse
    {
        $this->allow('delete', $party);

        $this->electionOf($party)->assertEditable();

        DB::transaction(function () use ($party): void {
            $this->lockElection($this->electionOf($party));

            $locked = Party::query()->whereKey($party->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->delete();
        });

        Log::info('party.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    private function electionOf(Party $party): Election
    {
        return $party->election ?? throw new ApiException(404, 'not_found');
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
     * @param  array{0: class-string<Party>, 1: Election}|Party  $subject
     */
    private function allow(string $ability, array|Party $subject): void
    {
        if (! Gate::forUser(request()->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

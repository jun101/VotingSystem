<?php

namespace App\Http\Controllers\Elections;

use App\Actions\Elections\DuplicateElection;
use App\Actions\Elections\ListElections;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Elections\CreateElectionRequest;
use App\Http\Requests\Elections\DuplicateElectionRequest;
use App\Http\Requests\Elections\ListElectionsRequest;
use App\Http\Requests\Elections\UpdateElectionRequest;
use App\Http\Resources\ElectionResource;
use App\Http\Resources\PageOf;
use App\Models\Election;
use App\Models\User;
use App\Support\Media\ImageReEncoder;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;

class ElectionController extends Controller
{
    /**
     * List the elections.
     *
     * The elections of the signed-in user's institution as cards, archived ones left out unless
     * `status=archived`. Order: open, scheduled, draft, closed, published, archived; then the latest
     * start, then the latest created. `meta.counts` (one per status, and `all` without the archived)
     * and `meta.years` (newest first, read in each election's time zone) ignore the filters. Owner or manager.
     *
     * @response array{data: list<array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}>, meta: array{page: int, per_page: int, total: int, counts: array{all: int, draft: int, scheduled: int, open: int, closed: int, published: int, archived: int}, years: list<int>}}
     */
    public function index(ListElectionsRequest $request, ListElections $list): PageOf
    {
        $this->allow('viewAny', Election::class);

        $result = $list($request->status(), $request->year(), $request->perPage(), $request->page());

        return PageOf::from($result['page'], ElectionResource::class)
            ->withMeta(['counts' => $result['counts'], 'years' => $result['years']]);
    }

    /**
     * Create an election.
     *
     * Always a draft of the caller's institution. The time zone and the language default from the
     * institution. A start in the past is accepted. Owner or manager. Limited to 60 requests per
     * hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}")]
    public function store(CreateElectionRequest $request): JsonResponse
    {
        $this->allow('create', Election::class);

        $user = $request->user();
        assert($user instanceof User);
        $institution = $user->institution;

        if ($institution === null) {
            throw new ApiException(403, 'forbidden');
        }

        // The time zone and the language of the institution, unless the request names its own.
        $election = new Election($request->attributesToWrite() + ['timezone' => $institution->timezone, 'language' => $institution->language]);
        $election->save();

        Log::info('election.create', ['outcome' => 'created']);

        return (new ElectionResource($election))->response()->setStatusCode(201);
    }

    /**
     * Show an election.
     *
     * One election of the institution, in any status. Owner or manager.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function show(Election $election): ElectionResource
    {
        $this->allow('view', $election);

        return new ElectionResource($election);
    }

    /**
     * Change an election.
     *
     * Every field is optional; only those sent change. The end is checked against the start as
     * they will be after the change. A draft only (409 `election_not_editable` otherwise). Owner or
     * manager. Limited to 120 requests per hour per user.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function update(UpdateElectionRequest $request, Election $election): ElectionResource
    {
        $this->allow('update', $election);

        $election->assertEditable();

        $changes = $request->attributesToWrite();

        $saved = DB::transaction(function () use ($election, $changes): Election {
            // The row is read again under the lock: a change that landed meanwhile counts.
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertEditable();
            $locked->fill($changes);

            // The dates as they will be, against the row as it is now (the validation read an older one).
            if (! $locked->ends_at->greaterThan($locked->starts_at)) {
                throw ValidationException::withMessages(['ends_at' => ['after_start']]);
            }

            $locked->save();

            return $locked;
        });

        Log::info('election.update', ['outcome' => 'updated']);

        return new ElectionResource($saved);
    }

    /**
     * Delete an election.
     *
     * A draft only. The row and its cover files go for good. Owner or manager. Limited to 60
     * requests per hour per user.
     */
    public function destroy(Election $election, ImageReEncoder $images): HttpResponse
    {
        $this->allow('delete', $election);

        $cover = null;

        DB::transaction(function () use ($election, &$cover): void {
            // The state is read again under the lock: a change that landed meanwhile counts.
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertEditable('election_not_deletable');
            $cover = $locked->cover_file;
            $locked->delete();
        });

        $images->delete($cover, ImageReEncoder::COVER_SIZES);

        Log::info('election.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    /**
     * Duplicate an election.
     *
     * A new draft with the settings of this one, whatever its status: description, dates, time
     * zone, language, candidate order, results display. Not the cover, the status dates or the
     * link to a first round. The title defaults to "Copie de …" ("Copy of …" for an English
     * election), cut to 200 characters. Owner or manager. Limited to 60 requests per hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}")]
    public function duplicate(DuplicateElectionRequest $request, Election $election, DuplicateElection $duplicate): JsonResponse
    {
        $this->allow('duplicate', $election);

        $copy = $duplicate($election, $request->givenTitle());

        Log::info('election.duplicate', ['outcome' => 'created']);

        return (new ElectionResource($copy))->response()->setStatusCode(201);
    }

    /**
     * Owners and managers of the election's institution may; anyone else (a platform admin) is
     * refused. Another institution's record never gets here: the binding answered 404.
     */
    private function allow(string $ability, Election|string $subject): void
    {
        $request = request();

        if (! Gate::forUser($request->user())->allows($ability, $subject)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

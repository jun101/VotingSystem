<?php

namespace App\Http\Controllers\Elections;

use App\Actions\Elections\ComputeChecklist;
use App\Actions\Elections\DuplicateElection;
use App\Actions\Elections\ListElections;
use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Elections\CloseElectionRequest;
use App\Http\Requests\Elections\CreateElectionRequest;
use App\Http\Requests\Elections\DuplicateElectionRequest;
use App\Http\Requests\Elections\ExtendElectionRequest;
use App\Http\Requests\Elections\ListElectionsRequest;
use App\Http\Requests\Elections\ScheduleElectionRequest;
use App\Http\Requests\Elections\UpdateElectionRequest;
use App\Http\Resources\ElectionResource;
use App\Http\Resources\PageOf;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Party;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterImport;
use App\Support\Media\ImageReEncoder;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Database\QueryException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Storage;
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
        $photos = [];
        $logos = [];
        $imports = [];
        $slips = [];

        try {
            DB::transaction(function () use ($election, &$cover, &$photos, &$logos, &$imports, &$slips): void {
                // The state is read again under the lock: a change that landed meanwhile counts.
                $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

                if ($locked === null) {
                    throw new ApiException(404, 'not_found');
                }

                $locked->assertEditable('election_not_deletable');
                $cover = $locked->cover_file;
                // Candidate photos and party logos go with the election (the rows by cascade, the
                // files after the commit).
                $photos = Candidate::query()->where('election_id', $locked->id)->whereNotNull('photo_file')->pluck('photo_file')->all();
                $logos = Party::query()->where('election_id', $locked->id)->whereNotNull('logo_file')->pluck('logo_file')->all();
                // The uploaded voter files go with it (the import rows by cascade, the files after the commit).
                $imports = VoterImport::query()->where('election_id', $locked->id)->pluck('stored_file')->all();
                // The slip files go with it (the rows by cascade, the stored copies after the commit).
                $slips = GeneratedFile::query()->where('election_id', $locked->id)->whereNotNull('stored_file')->pluck('stored_file')->all();
                // The voters go before the election: a group with voters is restricted, and the cascade
                // from the election may reach the groups first.
                // The ballots' group links go first too: the link restricts the deletion of a group.
                DB::table('ballot_voter_group')
                    ->whereIn('ballot_id', Ballot::query()->where('election_id', $locked->id)->select('id'))
                    ->delete();
                Voter::query()->where('election_id', $locked->id)->delete();
                $locked->delete();
            });
        } catch (QueryException $e) {
            // An election that holds votes, or a voter who voted, is held by the database (1451): a refusal, not a fault.
            if (($e->errorInfo[1] ?? null) === 1451) {
                throw new ApiException(409, 'election_not_deletable');
            }

            throw $e;
        }

        $images->delete($cover, ImageReEncoder::COVER_SIZES);

        foreach ($photos as $photo) {
            $images->delete(is_string($photo) ? $photo : null, ImageReEncoder::CANDIDATE_PHOTO_SIZES);
        }

        foreach ($logos as $logo) {
            $images->delete(is_string($logo) ? $logo : null, ImageReEncoder::PARTY_LOGO_SIZES);
        }

        Storage::disk('imports')->delete(array_values(array_filter($imports, is_string(...))));
        Storage::disk('credentials')->delete(array_values(array_filter($slips, is_string(...))));

        Log::info('election.delete', ['outcome' => 'deleted']);

        return response()->noContent();
    }

    /**
     * Duplicate an election.
     *
     * A new draft with the settings of this one, whatever its status: description, dates, time
     * zone, language, candidate order, results display. Not the cover, the status dates or the
     * link to a first round. The title defaults to "Copie de …" ("Copy of …" for an English
     * election), cut to 200 characters. Ballots and parties come with it, and the candidates when
     * `copy_candidates` is true. Owner or manager. Limited to 60 requests per hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}")]
    public function duplicate(DuplicateElectionRequest $request, Election $election, DuplicateElection $duplicate): JsonResponse
    {
        $this->allow('duplicate', $election);

        $copy = $duplicate($election, $request->givenTitle(), $request->copyCandidates());

        Log::info('election.duplicate', ['outcome' => 'created']);

        return (new ElectionResource($copy))->response()->setStatusCode(201);
    }

    /**
     * The checklist of an election.
     *
     * What stops the election from being scheduled (`blockers`) and what to look at first
     * (`warnings`), in any status. `can_schedule` is true for a draft with no blocker. Owner or
     * manager.
     *
     * @response array{data: array{status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', can_schedule: bool, counts: array{ballots: int, candidates: int, voters: int, groups: int}, blockers: list<array{code: 'no_ballots'|'ballot_without_candidates'|'no_voters', ballot?: array{id: string, title: string}}>, warnings: list<array{code: 'ballot_single_candidate'|'group_ballot_no_voters'|'group_ballot_few_voters'|'voters_without_ballot', ballot?: array{id: string, title: string}, count?: int}>}}
     */
    public function checklist(Election $election, ComputeChecklist $checklist): JsonResponse
    {
        $this->allow('view', $election);

        return response()->json(['data' => $checklist($election)]);
    }

    /**
     * Schedule an election.
     *
     * A draft becomes scheduled: ballots, candidates and parties are locked; voters and groups stay
     * editable. 409 `checklist_blocked` with the blockers in `error.items`; 409 `checklist_warnings`
     * with the warnings unless `accept_warnings` is true; 409 `election_period_over` when the end is
     * not in the future. The checklist is computed again under the row lock. Owner or manager.
     * Limited to 120 requests per hour per user.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function schedule(ScheduleElectionRequest $request, Election $election, ComputeChecklist $checklist): ElectionResource
    {
        $this->allow('update', $election);

        $accept = $request->acceptsWarnings();

        $saved = DB::transaction(function () use ($election, $accept, $checklist): Election {
            // The row is read again under the lock: a change that landed meanwhile counts.
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertEditable();

            if (! $locked->ends_at->isFuture()) {
                throw new ApiException(409, 'election_period_over');
            }

            $list = $checklist($locked);

            if ($list['blockers'] !== []) {
                throw new ApiException(409, 'checklist_blocked', items: $list['blockers']);
            }

            if ($list['warnings'] !== [] && ! $accept) {
                throw new ApiException(409, 'checklist_warnings', items: $list['warnings']);
            }

            $locked->status = ElectionStatus::Scheduled;
            $locked->save();

            return $locked;
        });

        Log::info('election.schedule', ['outcome' => 'scheduled']);

        return new ElectionResource($saved);
    }

    /**
     * Unschedule an election.
     *
     * A scheduled election goes back to draft, so ballots and candidates can be edited again.
     * Voters, groups and the groups of the ballots are not touched. Owner or manager. Limited to
     * 120 requests per hour per user. 409 `election_started` when the start has passed.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function unschedule(Election $election): ElectionResource
    {
        $this->allow('update', $election);

        $saved = DB::transaction(function () use ($election): Election {
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if ($locked->status !== ElectionStatus::Scheduled) {
                throw new ApiException(409, 'election_not_scheduled');
            }

            // The scheduler has not run yet but the start has passed: the election is as good as open.
            if (! $locked->starts_at->isFuture()) {
                throw new ApiException(409, 'election_started');
            }

            $locked->status = ElectionStatus::Draft;
            $locked->save();

            return $locked;
        });

        Log::info('election.unschedule', ['outcome' => 'unscheduled']);

        return new ElectionResource($saved);
    }

    /**
     * Close an election now.
     *
     * An open election is closed before its end, for good. `confirmation` must be the election's
     * title (case and surrounding spaces ignored, accents counted): 422 `confirmation: mismatch`.
     * 409 `election_not_open` for any other status. The status is read again under the row lock.
     * Nothing about votes is read or written. Owner or manager. Limited to 20 requests per hour
     * per user, shared with extend.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function close(CloseElectionRequest $request, Election $election): ElectionResource
    {
        $this->allow('update', $election);

        $confirmation = $request->confirmation();

        $saved = DB::transaction(function () use ($election, $confirmation): Election {
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if ($locked->status !== ElectionStatus::Open) {
                throw new ApiException(409, 'election_not_open');
            }

            if (mb_strtolower(trim($locked->title)) !== mb_strtolower($confirmation)) {
                throw ValidationException::withMessages(['confirmation' => ['mismatch']]);
            }

            $locked->status = ElectionStatus::Closed;
            $locked->closed_at = now('UTC');
            $locked->save();

            return $locked;
        });

        Log::info('election.close', ['outcome' => 'closed']);

        return new ElectionResource($saved);
    }

    /**
     * Extend an election.
     *
     * Moves the end of a scheduled or open election later; the status stays. The new end must be
     * after the current one (422 `ends_at: not_after`) and at most 30 days after it (`too_far`).
     * 409 `election_not_extendable` for another status, or when the end has passed. Owner or
     * manager. Limited to 20 requests per hour per user, shared with close.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function extend(ExtendElectionRequest $request, Election $election): ElectionResource
    {
        $this->allow('update', $election);

        $end = $request->newEnd();

        $saved = DB::transaction(function () use ($election, $end): Election {
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if (! in_array($locked->status, [ElectionStatus::Scheduled, ElectionStatus::Open], true) || ! $locked->ends_at->isFuture()) {
                throw new ApiException(409, 'election_not_extendable');
            }

            $current = $locked->ends_at->copy()->utc();

            if (! $end->greaterThan($current)) {
                throw ValidationException::withMessages(['ends_at' => ['not_after']]);
            }

            if ($end->greaterThan($current->copy()->addDays(30))) {
                throw ValidationException::withMessages(['ends_at' => ['too_far']]);
            }

            $locked->ends_at = Carbon::createFromInterface($end);
            $locked->save();

            return $locked;
        });

        Log::info('election.extend', ['outcome' => 'extended']);

        return new ElectionResource($saved);
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

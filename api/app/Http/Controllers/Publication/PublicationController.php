<?php

namespace App\Http\Controllers\Publication;

use App\Actions\Elections\ComputeResults;
use App\Enums\ElectionStatus;
use App\Enums\ResultsDisplay;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Publication\PublishElectionRequest;
use App\Http\Resources\ElectionResource;
use App\Models\Election;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class PublicationController extends Controller
{
    /**
     * Publish the results.
     *
     * Makes the results of a closed election public. `results_display` (`full` or `winners`) replaces the
     * election's setting when given. The row is locked, the status read again under the lock and the bulletin
     * check computed in the same transaction: 409 `results_check_failed` (`error.ballots`) when a ballot's
     * papers differ from its voters, 409 `election_not_closed` for any other status. A tie does not block it.
     * Owner or manager. Limited to 20 requests per hour per user, shared with unpublish, archive and unarchive.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function publish(PublishElectionRequest $request, Election $election, ComputeResults $results): ElectionResource
    {
        $this->allow($election);

        $display = $request->display();

        $saved = DB::transaction(function () use ($election, $display, $results): Election {
            $locked = $this->lock($election);

            if ($locked->status !== ElectionStatus::Closed) {
                throw new ApiException(409, 'election_not_closed');
            }

            // The same counting as the results page, inside the lock: a paper recorded meanwhile counts.
            $failed = [];

            foreach ($results($locked) as $ballot) {
                if (! $ballot['check']['ok']) {
                    $failed[] = $ballot['id'];
                }
            }

            if ($failed !== []) {
                throw new ApiException(409, 'results_check_failed', ballots: $failed);
            }

            if ($display !== null) {
                $locked->results_display = ResultsDisplay::from($display);
            }

            $locked->status = ElectionStatus::Published;
            $locked->published_at = now('UTC');
            $locked->save();

            return $locked;
        });

        Log::info('election.publish', ['outcome' => 'published']);

        return new ElectionResource($saved);
    }

    /**
     * Unpublish the results.
     *
     * A published election goes back to closed and `published_at` is cleared; the public pages answer 404 at
     * once. 409 `election_not_published` for any other status. Owner or manager. Limited as publish.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function unpublish(Election $election): ElectionResource
    {
        $this->allow($election);

        $saved = DB::transaction(function () use ($election): Election {
            $locked = $this->lock($election);

            if ($locked->status !== ElectionStatus::Published) {
                throw new ApiException(409, 'election_not_published');
            }

            $locked->status = ElectionStatus::Closed;
            $locked->published_at = null;
            $locked->save();

            return $locked;
        });

        Log::info('election.unpublish', ['outcome' => 'closed']);

        return new ElectionResource($saved);
    }

    /**
     * Archive an election.
     *
     * A closed or published election becomes archived (read-only, hidden from the default list) and `archived_at`
     * is set; an archived election is not public. 409 `election_not_archivable` for any other status. Owner or
     * manager. Limited as publish.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function archive(Election $election): ElectionResource
    {
        $this->allow($election);

        $saved = DB::transaction(function () use ($election): Election {
            $locked = $this->lock($election);

            if (! in_array($locked->status, [ElectionStatus::Closed, ElectionStatus::Published], true)) {
                throw new ApiException(409, 'election_not_archivable');
            }

            $locked->status = ElectionStatus::Archived;
            $locked->archived_at = now('UTC');
            $locked->save();

            return $locked;
        });

        Log::info('election.archive', ['outcome' => 'archived']);

        return new ElectionResource($saved);
    }

    /**
     * Unarchive an election.
     *
     * An archived election goes back to closed; `archived_at` and `published_at` are cleared (it is published
     * again on purpose). 409 `election_not_archived` for any other status. Owner or manager. Limited as publish.
     *
     * @response array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string}}
     */
    public function unarchive(Election $election): ElectionResource
    {
        $this->allow($election);

        $saved = DB::transaction(function () use ($election): Election {
            $locked = $this->lock($election);

            if ($locked->status !== ElectionStatus::Archived) {
                throw new ApiException(409, 'election_not_archived');
            }

            $locked->status = ElectionStatus::Closed;
            $locked->archived_at = null;
            $locked->published_at = null;
            $locked->save();

            return $locked;
        });

        Log::info('election.unarchive', ['outcome' => 'closed']);

        return new ElectionResource($saved);
    }

    /** The row, read again under the lock: a change that landed meanwhile counts. */
    private function lock(Election $election): Election
    {
        $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

        if ($locked === null) {
            throw new ApiException(404, 'not_found');
        }

        return $locked;
    }

    /** Owners and managers of the election's institution may; anyone else (a platform admin) is refused. */
    private function allow(Election $election): void
    {
        if (! Gate::forUser(request()->user())->allows('update', $election)) {
            throw new ApiException(403, 'forbidden');
        }
    }
}

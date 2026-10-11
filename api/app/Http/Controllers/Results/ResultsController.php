<?php

namespace App\Http\Controllers\Results;

use App\Actions\Elections\ComputeResults;
use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class ResultsController extends Controller
{
    /**
     * The results of an election.
     *
     * Per ballot, in the election's order: the voters eligible and those who voted, the papers cast
     * (blank included), the blank ones, the candidates by votes then by the election's order with
     * their percentage of the papers cast (one decimal), the winners, the tie for the last seats
     * (never broken) and the bulletin check. Only for a closed, published or archived election:
     * 409 `results_not_available` otherwise. Counted from the votes and their choices only; the
     * audit table is never read. Owner or manager.
     *
     * @response array{data: array{election: array{id: string, title: string, status: 'closed'|'published'|'archived', closed_at: string|null}, ballots: list<array{id: string, title: string, seats: int, scope: 'general'|'group', groups: list<array{id: string, name: string}>, eligible: int, voted: int, cast: int, blank: int, candidates: list<array{id: string, first_name: string, last_name: string, party: string|null, votes: int, percentage: float}>, winners: list<string>, tie: array{candidates: list<string>, seats_left: int}|null, check: array{recorded: int, expected: int, ok: bool}, note: string|null, second_round: string|null}>}}
     */
    public function __invoke(Election $election, ComputeResults $results): JsonResponse
    {
        if (! Gate::forUser(request()->user())->allows('view', $election)) {
            throw new ApiException(403, 'forbidden');
        }

        if (! in_array($election->status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
            throw new ApiException(409, 'results_not_available');
        }

        return response()->json([
            'data' => [
                'election' => ['id' => $election->uuid, 'title' => $election->title, 'status' => $election->status->value, 'closed_at' => $election->closed_at?->utc()->format('Y-m-d\TH:i:s\Z')],
                'ballots' => $results($election),
            ],
        ], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}

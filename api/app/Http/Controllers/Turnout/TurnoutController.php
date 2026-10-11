<?php

namespace App\Http\Controllers\Turnout;

use App\Actions\Elections\ComputeTurnout;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Elections\TurnoutRequest;
use App\Models\Election;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;

class TurnoutController extends Controller
{
    /**
     * The turnout of an election.
     *
     * How many voters have voted, in total, by group and over time, in any status. `bucket` is
     * `hour` or `day` (default: hour for an election of up to 3 days, else day); the series runs in
     * the election's time zone, empty buckets listed, empty while nobody has voted. Computed from
     * the participations only: never a vote, never what was voted. Owner or manager.
     *
     * @response array{data: array{eligible: int, voted: int, percentage: float, by_group: list<array{group: array{id: string, name: string}|null, voters: int, voted: int}>, bucket: 'hour'|'day', over_time: list<array{at: string, voted: int, total: int}>}}
     */
    public function __invoke(TurnoutRequest $request, Election $election, ComputeTurnout $turnout): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('view', $election)) {
            throw new ApiException(403, 'forbidden');
        }

        return response()->json(['data' => $turnout($election, $request->bucket())], 200, [], JSON_PRESERVE_ZERO_FRACTION);
    }
}

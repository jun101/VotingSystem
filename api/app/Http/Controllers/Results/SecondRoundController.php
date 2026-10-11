<?php

namespace App\Http\Controllers\Results;

use App\Actions\Elections\CreateSecondRound;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Http\Requests\Results\SecondRoundRequest;
use App\Http\Resources\ElectionResource;
use App\Models\Election;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Log;

class SecondRoundController extends Controller
{
    /**
     * Create a second round.
     *
     * A new draft election from a ballot of a closed, published or archived election whose result
     * has a tie: that ballot with only the tied candidates and the seats left, the same groups and
     * voters (no code, no vote). Title "Second tour – …" ("Second round – …" for an English
     * election), cut to 200 characters. 409 `ballot_not_tied`, `second_round_exists`,
     * `results_not_available`; 422 `ballot: invalid`. `parent` is the first election's UUID, in
     * this response only. Owner or manager. Limited to 20 requests per hour per user.
     */
    #[Response(status: 201, type: "array{data: array{id: string, title: string, description: string|null, status: 'draft'|'scheduled'|'open'|'closed'|'published'|'archived', starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', candidate_order: 'manual'|'shuffled', results_display: 'full'|'winners', cover: array{sm: string, md: string}|null, ballots_count: int, voters_count: int, created_at: string, parent: string}}")]
    public function __invoke(SecondRoundRequest $request, Election $election, CreateSecondRound $create): JsonResponse
    {
        if (! Gate::forUser($request->user())->allows('update', $election)) {
            throw new ApiException(403, 'forbidden');
        }

        $new = $create($election, $request->ballotUuid());

        Log::info('election.second_round', ['outcome' => 'created']);

        // The first election's UUID is named in this response only (`parent`).
        $data = (new ElectionResource($new))->resolve() + ['parent' => $election->uuid];

        return response()->json(['data' => $data], 201);
    }
}

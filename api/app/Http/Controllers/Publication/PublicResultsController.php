<?php

namespace App\Http\Controllers\Publication;

use App\Actions\Elections\ComposePublishedResults;
use App\Http\Controllers\Controller;
use App\Support\Publication\PublishedElections;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class PublicResultsController extends Controller
{
    /**
     * The public results of a published election.
     *
     * No sign-in, no cookie. Only a `published` election of an institution that is not suspended; any other
     * status, an unknown election and a value that is not a UUID answer the same 404. With the `full` display:
     * votes and percentages per candidate and the blank votes; with `winners`: the turnout, the winners and the
     * tied candidates only. Never the bulletin check, a voter or a numeric key. Limited to 120 requests per
     * minute per address and election. `Cache-Control: no-cache`.
     */
    #[Response(status: 200, type: "array{data: array{id: string, title: string, language: 'fr'|'en', timezone: string, starts_at: string, ends_at: string, published_at: string|null, display: 'full'|'winners', institution: array{id: string, name: string, logo: array{sm: string, md: string, lg: string}|null}, turnout: array{eligible: int, voted: int, percentage: float}, ballots: list<array{id: string, title: string, seats: int, groups: list<string>, cast?: int, blank?: int, candidates: list<array{id: string, first_name: string, last_name: string, party: string|null, photo: string|null, votes?: int, percentage?: float}>, winners: list<string>, tie: array{candidates: list<string>, seats_left: int}|null, note: string|null}>}}")]
    public function __invoke(string $election, PublishedElections $elections, ComposePublishedResults $composer): JsonResponse
    {
        $found = $elections->find($election);

        return response()->json(['data' => $composer->payload($found)], 200, ['Cache-Control' => 'no-cache'], JSON_PRESERVE_ZERO_FRACTION);
    }
}

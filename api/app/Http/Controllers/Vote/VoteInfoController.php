<?php

namespace App\Http\Controllers\Vote;

use App\Enums\ElectionStatus;
use App\Http\Controllers\Controller;
use App\Models\Institution;
use App\Support\Voting\VotingElections;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;

class VoteInfoController extends Controller
{
    /**
     * What a voter may know before entering a code.
     *
     * The election, its period and whether it can be voted in now (`phase`: `before`, `open` or
     * `closed`, from the status and the server's clock, never the device's). `results` is the
     * path of the public results page once the election is published. A draft, an unknown election
     * and a value that is not a UUID all answer 404. No session; no count; no ballot. Limited per
     * address and election.
     */
    #[Response(status: 200, type: "array{data: array{id: string, title: string, institution: string, starts_at: string, ends_at: string, timezone: string, language: 'fr'|'en', phase: 'before'|'open'|'closed', results: string|null}}")]
    public function __invoke(string $election, VotingElections $elections): JsonResponse
    {
        $found = $elections->find($election);

        // The institution is the tenant itself: it is not a scoped model.
        $name = Institution::query()->whereKey($found->institution_id)->value('name');

        return response()->json([
            'data' => [
                'id' => $found->uuid,
                'title' => $found->title,
                'institution' => is_string($name) ? $name : '',
                'starts_at' => $found->starts_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'ends_at' => $found->ends_at->utc()->format('Y-m-d\TH:i:s\Z'),
                'timezone' => $found->timezone,
                'language' => $found->language,
                'phase' => $elections->phase($found),
                'results' => $found->status === ElectionStatus::Published ? '/results/'.$found->uuid : null,
            ],
        ]);
    }
}

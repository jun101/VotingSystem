<?php

namespace App\Http\Controllers\Vote;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\VoterGroup;
use App\Support\Voting\VotePhase;
use App\Support\Voting\VoterBallots;
use App\Support\Voting\VoterContexts;
use App\Support\Voting\VotingElections;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class VoteBallotsController extends Controller
{
    /**
     * The voter's name and group, and the ballots they may fill.
     *
     * Only the ballots the voter is eligible for (the general ones and those of their group), each
     * with its candidates, party, photo and slogan, in the election's order; a `shuffled` order is
     * fixed for the session. The voter has no id here: the session names them. 401
     * `voter_session_expired` without a live session of this election; 409 `already_voted` for a
     * session that voted; 409 `election_not_open` when the election is not open now.
     */
    #[Response(status: 200, type: "array{data: array{voter: array{full_name: string, group: string|null}, expires_at: string, ballots: list<array{id: string, title: string, description: string|null, scope: 'general'|'group', group_names: list<string>, seats: int, allow_blank: bool, candidates: list<array{id: string, first_name: string, last_name: string, sex: 'female'|'male'|'other', slogan: string|null, biography: string|null, photo: string|null, party: array{name: string, acronym: string|null, colour: string, logo: string|null}|null}>}>}}")]
    public function __invoke(Request $request, string $election, VotingElections $elections, VoterContexts $contexts, VoterBallots $ballots): JsonResponse
    {
        $found = $elections->find($election);
        $context = $contexts->resolve($request, $found);

        if ($context->session->hasVoted()) {
            throw new ApiException(409, 'already_voted');
        }

        $phase = $elections->phase($found);

        if ($phase !== VotePhase::OPEN) {
            throw new ApiException(409, 'election_not_open', phase: $phase);
        }

        $voter = $context->voter;
        // The voter's group name, for a voter without an institution user.
        $group = $voter->voter_group_id === null ? null : VoterGroup::withoutInstitutionScope()->whereKey($voter->voter_group_id)->value('name');

        return response()->json([
            'data' => [
                'voter' => ['full_name' => $voter->full_name, 'group' => is_string($group) ? $group : null],
                'expires_at' => gmdate('Y-m-d\TH:i:s\Z', $context->session->expiresAt),
                'ballots' => $ballots->present($found, $voter, $context->session->seed),
            ],
        ], 200, ['Cache-Control' => 'no-store']);
    }
}

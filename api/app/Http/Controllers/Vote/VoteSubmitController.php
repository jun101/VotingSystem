<?php

namespace App\Http\Controllers\Vote;

use App\Actions\Voting\RecordVote;
use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Support\Voting\VoterContexts;
use App\Support\Voting\VoterSessions;
use App\Support\Voting\VotingElections;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;
use Throwable;

class VoteSubmitController extends Controller
{
    /**
     * Record the vote.
     *
     * Every ballot the voter is eligible for, together, once, in one transaction with the voter's
     * row locked: the election is open (status and server time), there is no participation yet, the
     * ballots are exactly the eligible ones (422 `ballots_mismatch`) and each ballot's choices
     * break no rule (422 `choices_invalid`); then the participation, one paper per ballot, its
     * choices and its sealed audit record are written, or nothing is. The same submission sent
     * again in the same session answers 200 with the same time and writes nothing. The choices are
     * never returned and never logged. 10 requests per minute per session.
     */
    #[Response(status: 201, type: 'array{data: array{voted_at: string}}')]
    public function __invoke(Request $request, string $election, VotingElections $elections, VoterContexts $contexts, VoterSessions $sessions, RecordVote $record): JsonResponse
    {
        $found = $elections->find($election);
        $context = $contexts->resolve($request, $found);

        // Every request of the session counts, valid or not.
        $limit = 'vote-submit:'.VoterSessions::fingerprint($context->session->token);
        $max = Config::integer('votes.submit_per_minute');

        if (RateLimiter::tooManyAttempts($limit, $max)) {
            throw new ApiException(429, 'too_many_attempts', ['Retry-After' => (string) max(1, RateLimiter::availableIn($limit))]);
        }

        RateLimiter::hit($limit, 60);

        /** @var array{ballots: list<array{ballot: string, candidates: list<string>}>} $body */
        $body = $request->validate([
            'ballots' => ['bail', 'required', 'array', 'list', 'min:1', 'max:50'],
            'ballots.*' => ['bail', 'array'],
            'ballots.*.ballot' => ['bail', 'required', 'string', 'uuid'],
            'ballots.*.candidates' => ['bail', 'present', 'array', 'list', 'max:50'],
            'ballots.*.candidates.*' => ['bail', 'required', 'string', 'uuid'],
        ]);

        // A lost answer sent again in the same session: the same confirmation, nothing written.
        if ($context->session->votedAt !== null) {
            return $this->confirmation($context->session->votedAt, 200);
        }

        $votedAt = $record($found, $context->session, $body['ballots']);

        // The vote is committed: a cache that cannot be written now must not turn it into an error. The
        // session then stays unmarked, and a repeat answers 409 `already_voted` from the participation.
        try {
            $sessions->markVoted($context->session, $votedAt->getTimestamp());
        } catch (Throwable) {
            // Nothing to do: the vote stands.
        }

        return $this->confirmation($votedAt->getTimestamp(), 201);
    }

    private function confirmation(int $votedAt, int $status): JsonResponse
    {
        return response()->json(['data' => ['voted_at' => gmdate('Y-m-d\TH:i:s\Z', $votedAt)]], $status, ['Cache-Control' => 'no-store']);
    }
}

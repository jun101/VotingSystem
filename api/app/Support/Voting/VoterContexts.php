<?php

namespace App\Support\Voting;

use App\Exceptions\ApiException;
use App\Models\Credential;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Http\Request;

/**
 * Turns the cookie of a request into the voter it names (401 `voter_session_expired` when it
 * names nobody, for any reason: no cookie, a token that is not one, unknown, ended, expired, of
 * another election, or opened with a code that has been replaced since).
 *
 * Stateless: nothing of the request is kept.
 */
final class VoterContexts
{
    public function __construct(private readonly VoterSessions $sessions) {}

    /** @throws ApiException 401 `voter_session_expired` */
    public function resolve(Request $request, Election $election): VoterContext
    {
        $cookie = $request->cookies->get(VoterSessions::COOKIE);
        $session = $this->sessions->find(is_string($cookie) ? $cookie : null);

        if ($session === null || $session->electionId !== $election->id) {
            throw $this->expired();
        }

        // The voter of the session, in the election of the session: a voter has no institution user to scope by.
        $voter = Voter::withoutInstitutionScope()->whereKey($session->voterId)->where('election_id', $election->id)->first();

        if ($voter === null || ! $this->codeIsCurrent($voter, $session)) {
            $this->sessions->forget($session->token);

            throw $this->expired();
        }

        return new VoterContext($election, $voter, $session);
    }

    /** The code the session was opened with is still the voter's current one (FR-CRED-03). */
    public function codeIsCurrent(Voter $voter, VoterSession $session): bool
    {
        // The credential of the voter of this session, for a voter without an institution user.
        $credential = Credential::withoutInstitutionScope()->where('voter_id', $voter->id)->first();

        return $credential !== null
            && (int) $credential->reissue_count === $session->reissueCount
            && $credential->issued_at->getTimestamp() === $session->issuedAt;
    }

    public function expired(): ApiException
    {
        return new ApiException(401, 'voter_session_expired');
    }
}

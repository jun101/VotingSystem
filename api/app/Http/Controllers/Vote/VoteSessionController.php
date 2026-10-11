<?php

namespace App\Http\Controllers\Vote;

use App\Exceptions\ApiException;
use App\Http\Controllers\Controller;
use App\Models\Credential;
use App\Models\Voter;
use App\Models\Voting\Participation;
use App\Support\Credentials\AccessCode;
use App\Support\Voting\CodeAttempts;
use App\Support\Voting\TypedCode;
use App\Support\Voting\VotePhase;
use App\Support\Voting\VoterSessions;
use App\Support\Voting\VotingElections;
use Dedoc\Scramble\Attributes\Response;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Response as HttpResponse;
use Illuminate\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Cookie;

class VoteSessionController extends Controller
{
    /** Where the cookie of a voter session is sent: the voting routes only. */
    public const COOKIE_PATH = '/api/v1/vote';

    /**
     * Enter a code and open a voter session.
     *
     * The code (any case, with or without the hyphen, spaces ignored) is hashed with the credential
     * key and looked up in this election only; an unknown, wrong, another election's, malformed or
     * replaced code answers the same 401 `invalid_code`, after the same work. A valid code opens a
     * session: a random token in the `vote_session` cookie (HttpOnly, SameSite=Strict, path
     * `/api/v1/vote`), kept at most 15 minutes; a session the browser already had is replaced.
     * Only wrong codes are counted: 30 in 10 minutes per address (an IPv6 client by its /64) and election, 300 in an hour per
     * election, 429 with `Retry-After` (a locked-out address is refused even with a valid code; the election
     * limit refuses wrong codes only). A voter has one live session: a new one ends the earlier one.
     * 409 `already_voted`, then 409 `election_not_open` with its `phase`.
     */
    #[Response(status: 201, type: 'array{data: array{expires_at: string}}')]
    public function store(Request $request, string $election, VotingElections $elections, VoterSessions $sessions, CodeAttempts $attempts): JsonResponse
    {
        $found = $elections->find($election);

        $request->validate(['code' => ['bail', 'required', 'string']]);

        // No code is this long: refused before any work is done on it, and not counted as a wrong code.
        if (mb_strlen($request->string('code')->toString()) > TypedCode::MAX) {
            throw ValidationException::withMessages(['code' => ['invalid']]);
        }

        $address = (string) $request->ip();

        $wait = $attempts->beginCode($found->uuid, $address);

        // A locked-out address is refused even with a valid code.
        if ($wait['address'] !== null) {
            throw new ApiException(429, 'too_many_attempts', ['Retry-After' => (string) max($wait['address'], $wait['election'] ?? 0)]);
        }

        // Spaces and hyphens ignored, upper case; hashed and looked up whatever was typed: same work for every answer.
        $hash = AccessCode::hash(TypedCode::normalise($request->string('code')->toString()));

        // The code is looked up in this election only (the unique key is (election, hash)): another election's code never matches.
        // The voter has no institution user, so the tenant scope is removed by name.
        $credential = Credential::withoutInstitutionScope()
            ->where('election_id', $found->id)
            ->where('code_hash', $hash)
            ->first();

        if ($credential === null) {
            $this->wrongCode($attempts, $found->uuid, $address, $wait['election']);
        }

        // The voter of that code: a voter has no institution user to scope by.
        $voter = Voter::withoutInstitutionScope()->whereKey($credential->voter_id)->where('election_id', $found->id)->first();

        if ($voter === null) {
            $this->wrongCode($attempts, $found->uuid, $address, $wait['election']);
        }

        // The code is valid: only wrong codes count, so the request is given back.
        $attempts->forgive($found->uuid, $address);

        if (Participation::query()->where('election_id', $found->id)->where('voter_id', $voter->id)->exists()) {
            throw new ApiException(409, 'already_voted');
        }

        $phase = $elections->phase($found);

        if ($phase !== VotePhase::OPEN) {
            throw new ApiException(409, 'election_not_open', phase: $phase);
        }

        // A browser that already has a session leaves it for this one.
        $previous = $request->cookies->get(VoterSessions::COOKIE);
        $sessions->forget(is_string($previous) ? $previous : null);

        $session = $sessions->open($voter->id, $found->id, (int) $credential->reissue_count, $credential->issued_at->getTimestamp());

        // The token is in the cookie only: not in the body, a header of its own or a log.
        $response = response()->json(
            ['data' => ['expires_at' => gmdate('Y-m-d\TH:i:s\Z', $session->expiresAt)]],
            201,
            ['Cache-Control' => 'no-store'],
        );
        $response->headers->setCookie(new Cookie(VoterSessions::COOKIE, $session->token, $session->expiresAt, self::COOKIE_PATH, null, $this->secure($request), true, false, Cookie::SAMESITE_STRICT));

        return $response;
    }

    /**
     * End the voter session.
     *
     * "Not me", "next voter" or leaving: the session is forgotten and the cookie cleared. No
     * session, an expired one or another election's session is nothing to end, and answers the
     * same 204. A vote or a participation never changes.
     */
    public function destroy(Request $request, string $election, VotingElections $elections, VoterSessions $sessions): HttpResponse
    {
        $cookie = $request->cookies->get(VoterSessions::COOKIE);
        $token = is_string($cookie) ? $cookie : null;
        $response = response()->noContent();

        try {
            $found = $elections->find($election);
        } catch (ApiException) {
            // An election that cannot be voted in has no session to end: the same answer, nothing touched.
            return $response;
        }

        $session = $sessions->find($token);

        // Another election's live session is left as it is: this address cannot end it.
        if ($session !== null && $session->electionId !== $found->id) {
            return $response;
        }

        $sessions->forget($token);

        if ($token !== null) {
            $response->headers->setCookie(new Cookie(VoterSessions::COOKIE, '', 1, self::COOKIE_PATH, null, $this->secure($request), true, false, Cookie::SAMESITE_STRICT));
        }

        return $response;
    }

    /**
     * A wrong code: counted (begin did), and refused with 429 when the election-wide limit is reached,
     * with only that counter given back so it stays at its maximum (the address keeps the failure counted);
     * else 401 `invalid_code`.
     */
    private function wrongCode(CodeAttempts $attempts, string $election, string $address, ?int $electionWait): never
    {
        if ($electionWait !== null) {
            $attempts->forgiveElection($election, $address);

            throw new ApiException(429, 'too_many_attempts', ['Retry-After' => (string) $electionWait]);
        }

        throw new ApiException(401, 'invalid_code');
    }

    /** `Secure` outside local development, and whenever the request itself came over TLS. */
    private function secure(Request $request): bool
    {
        return $request->isSecure() || ! app()->isLocal();
    }
}

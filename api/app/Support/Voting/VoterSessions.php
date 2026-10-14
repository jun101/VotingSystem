<?php

namespace App\Support\Voting;

use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Config;

/**
 * The voter sessions (docs/slices/12-voting-flow.md "Session"): a random 256-bit token in a
 * cookie, a record in the cache for at most `votes.session_minutes`.
 *
 * Stateless, for Octane: every call reads the cache and the application clock. The expiry is held
 * in the record and compared with `Carbon::now()`, so a test that travels in time ends a session as
 * a real clock does, whatever the cache store does with its own time.
 *
 * A voter has one live session (slice 12b): opening one records the hash of its token as the voter's
 * current one, and a session whose token is not the current one is ended. Cache only, hash only.
 */
final class VoterSessions
{
    public const COOKIE = 'vote_session';

    /** A new session for a voter who entered a valid code. */
    public function open(int $voterId, int $electionId, int $reissueCount, int $issuedAt): VoterSession
    {
        $token = rtrim(strtr(base64_encode(random_bytes(32)), '+/', '-_'), '=');
        $ttl = Config::integer('votes.session_minutes') * 60;

        $session = new VoterSession(
            token: $token,
            voterId: $voterId,
            electionId: $electionId,
            expiresAt: Carbon::now()->getTimestamp() + $ttl,
            votedAt: null,
            seed: bin2hex(random_bytes(16)),
            reissueCount: $reissueCount,
            issuedAt: $issuedAt,
        );

        Cache::put(self::key($token), $session->toArray(), $ttl);
        // The newest session is the voter's only live one: any earlier token is ended from now on.
        Cache::put(self::currentKey($electionId, $voterId), self::fingerprint($token), $ttl);

        return $session;
    }

    /** The live session of a token, or null: unknown, malformed, ended or expired. */
    public function find(?string $token): ?VoterSession
    {
        if (! self::looksLikeToken($token)) {
            return null;
        }

        $data = Cache::get(self::key($token));

        if (! is_array($data)) {
            return null;
        }

        $session = VoterSession::fromArray($token, $data);

        if ($session === null || $session->expiresAt <= Carbon::now()->getTimestamp()) {
            Cache::forget(self::key($token));

            return null;
        }

        // Not the voter's current token (a newer session was opened, or the newest one ended): ended for good.
        $current = Cache::get(self::currentKey($session->electionId, $session->voterId));

        if (! is_string($current) || ! hash_equals($current, self::fingerprint($token))) {
            Cache::forget(self::key($token));

            return null;
        }

        return $session;
    }

    /** Ends a session. A token that is not one, or is unknown, is nothing to end. */
    public function forget(?string $token): void
    {
        if (self::looksLikeToken($token)) {
            Cache::forget(self::key($token));
        }
    }

    /** The session keeps its remaining minutes, in a state that answers "voted". */
    public function markVoted(VoterSession $session, int $votedAt): void
    {
        $remaining = $session->expiresAt - Carbon::now()->getTimestamp();

        if ($remaining <= 0) {
            return;
        }

        Cache::put(self::key($session->token), [...$session->toArray(), 'voted_at' => $votedAt], $remaining);
    }

    /** What the rate limiter keys on: the hash of the token, not the token. */
    public static function fingerprint(string $token): string
    {
        return hash('sha256', $token);
    }

    /** @phpstan-assert-if-true string $token */
    public static function looksLikeToken(?string $token): bool
    {
        return is_string($token) && preg_match('/\A[A-Za-z0-9_-]{43}\z/', $token) === 1;
    }

    /** The voter's current token, as a hash: the numeric keys stay in the cache and are never output. */
    private static function currentKey(int $electionId, int $voterId): string
    {
        return 'voter-session-current:'.$electionId.':'.$voterId;
    }

    private static function key(string $token): string
    {
        return 'vote-session:'.self::fingerprint($token);
    }
}

<?php

namespace App\Support\Voting;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The wrong codes counted for an election (docs/api/vote/POST-vote-{election}-session.md):
 * 30 in 10 minutes from one address, and 300 in an hour from everywhere. Only a wrong code is
 * counted; a valid code is never. A locked-out address is refused even with a valid code.
 *
 * Stateless: the counters are in the cache. Addresses are hashed in the keys, an IPv6 client by its /64.
 */
final class CodeAttempts
{
    /**
     * Counts this request against both limits at once: the increment itself decides, so two
     * requests at the same moment cannot both slip under the maximum. Null when the request may go
     * on (it stays counted until `forgive` or until it is known to be a wrong code); else the
     * seconds to wait, with the request given back (a locked-out address stays at its maximum).
     */
    public function begin(string $election, string $address): ?int
    {
        [$addressWait, $electionWait] = $this->count($election, $address);
        $waits = array_filter([$addressWait, $electionWait], static fn (?int $wait): bool => $wait !== null);

        if ($waits === []) {
            return null;
        }

        $this->forgive($election, $address);

        return max($waits);
    }

    /**
     * Like `begin`, for the code entry, where the election-wide limit slows only wrong codes. The
     * address limit refuses at once (the request is given back, valid code or not). The election
     * limit is returned as a wait to apply only once the code is known to be wrong; the request stays
     * counted until `forgive` (a valid code) or, for a refusal, until the caller gives it back.
     *
     * @return array{address: int|null, election: int|null} seconds to wait, null when not exceeded
     */
    public function beginCode(string $election, string $address): array
    {
        [$addressWait, $electionWait] = $this->count($election, $address);

        if ($addressWait !== null) {
            $this->forgive($election, $address);
        }

        return ['address' => $addressWait, 'election' => $electionWait];
    }

    /**
     * Increments both counters at once (the increment decides, atomically) and tells which are exceeded.
     *
     * @return array{int|null, int|null} wait of the address limit, wait of the election limit
     */
    private function count(string $election, string $address): array
    {
        $waits = [];

        foreach ($this->limits($election, $address) as [$key, $max, $window]) {
            $waits[] = RateLimiter::increment($key, $window) > $max ? max(1, RateLimiter::availableIn($key)) : null;
        }

        return [$waits[0], $waits[1]];
    }

    /** The request was not a wrong code (a valid code, or a refusal): it is given back. */
    public function forgive(string $election, string $address): void
    {
        foreach ($this->limits($election, $address) as [$key, , $window]) {
            RateLimiter::decrement($key, $window);
        }
    }

    /**
     * A wrong code refused by the election-wide limit: only that counter is given back (it stays at its maximum).
     * The wrong code stays counted on the address, so a saturated election is no way to guess without the
     * per-address limit.
     */
    public function forgiveElection(string $election, string $address): void
    {
        [, [$key, , $window]] = $this->limits($election, $address);

        RateLimiter::decrement($key, $window);
    }

    /**
     * Seconds to wait when the limits are already exhausted for this address, else null. A read
     * only (it counts nothing); the controller uses `begin`, whose increment decides atomically.
     */
    public function lockedFor(string $election, string $address): ?int
    {
        $waits = [];

        foreach ($this->limits($election, $address) as [$key, $max]) {
            if (RateLimiter::tooManyAttempts($key, $max)) {
                $waits[] = max(1, RateLimiter::availableIn($key));
            }
        }

        return $waits === [] ? null : max($waits);
    }

    /** One wrong code counted on its own (both counters go up), for what counts a code after the fact. */
    public function failed(string $election, string $address): void
    {
        foreach ($this->limits($election, $address) as [$key, , $window]) {
            RateLimiter::hit($key, $window);
        }
    }

    /** @return list<array{string, int, int}> key, maximum, window in seconds */
    private function limits(string $election, string $address): array
    {
        return [
            [
                'vote-code:address:'.$election.':'.ClientAddress::bucket($address),
                Config::integer('votes.code_failures_per_address'),
                Config::integer('votes.code_failures_address_window_seconds'),
            ],
            [
                'vote-code:election:'.$election,
                Config::integer('votes.code_failures_per_election'),
                Config::integer('votes.code_failures_election_window_seconds'),
            ],
        ];
    }
}

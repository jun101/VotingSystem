<?php

namespace App\Support\Voting;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\RateLimiter;

/**
 * The wrong codes counted for an election (docs/api/vote/POST-vote-{election}-session.md):
 * 30 in 10 minutes from one address, and 300 in an hour from everywhere. Only a wrong code is
 * counted; a valid code is never. A locked-out address is refused even with a valid code.
 *
 * Stateless: the counters are in the cache. Addresses are hashed in the keys.
 */
final class CodeAttempts
{
    /** Seconds to wait when the election's wrong-code limits are exhausted for this address, else null. */
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

    /** One wrong code: both counters go up. */
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
                'vote-code:address:'.$election.':'.hash('sha256', $address),
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

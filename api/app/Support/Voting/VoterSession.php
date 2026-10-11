<?php

namespace App\Support\Voting;

/**
 * What the server remembers about a voter who entered a valid code (docs/api/vote/POST-vote-{election}-session.md).
 * Kept in the cache under the hash of the token, never the token itself, for at most the session's
 * minutes. The numeric keys stay in the cache: they are never output.
 *
 * `$reissueCount` and `$issuedAt` are those of the voter's code when the session opened: a code
 * replaced since ends the sessions it opened (FR-CRED-03).
 */
final readonly class VoterSession
{
    public function __construct(
        public string $token,
        public int $voterId,
        public int $electionId,
        public int $expiresAt,
        public ?int $votedAt,
        public string $seed,
        public int $reissueCount,
        public int $issuedAt,
    ) {}

    public function hasVoted(): bool
    {
        return $this->votedAt !== null;
    }

    /** @return array{voter: int, election: int, expires_at: int, voted_at: int|null, seed: string, reissues: int, issued_at: int} */
    public function toArray(): array
    {
        return [
            'voter' => $this->voterId,
            'election' => $this->electionId,
            'expires_at' => $this->expiresAt,
            'voted_at' => $this->votedAt,
            'seed' => $this->seed,
            'reissues' => $this->reissueCount,
            'issued_at' => $this->issuedAt,
        ];
    }

    /** @param  array<array-key, mixed>  $data */
    public static function fromArray(string $token, array $data): ?self
    {
        $ints = ['voter', 'election', 'expires_at', 'reissues', 'issued_at'];

        foreach ($ints as $key) {
            if (! isset($data[$key]) || ! is_int($data[$key])) {
                return null;
            }
        }

        $votedAt = $data['voted_at'] ?? null;

        if (($votedAt !== null && ! is_int($votedAt)) || ! isset($data['seed']) || ! is_string($data['seed'])) {
            return null;
        }

        return new self($token, $data['voter'], $data['election'], $data['expires_at'], $votedAt, $data['seed'], $data['reissues'], $data['issued_at']);
    }
}

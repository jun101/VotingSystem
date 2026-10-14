<?php

namespace App\Actions\Credentials;

use App\Models\Election;
use App\Models\VoterGroup;
use App\Support\Credentials\Slip;

/**
 * What the first phase of a file's generation hands to the next two: the slips in memory, the
 * codes written (voter key and hash, to take back on a failure) and the name the PDF is stored under.
 * It lives for one job only.
 */
final class ClaimedFile
{
    /**
     * @param  list<Slip>  $slips
     * @param  list<array{voter: int, hash: string, previous: array<string, mixed>|null}>  $issued  `previous` is the row a replaced code had (null for a first code)
     * @param  list<array{voter: int, uuid: string, hash: string, code: string}>  $emails  the messages to queue once the file is `ready`
     */
    public function __construct(
        public readonly Election $election,
        public readonly ?VoterGroup $group,
        public readonly array $slips,
        public readonly array $issued,
        public readonly array $emails,
        public readonly string $name,
        public readonly string $institution,
        public readonly int $part,
        public readonly int $parts,
    ) {}
}

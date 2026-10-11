<?php

namespace App\Support\Credentials;

use Carbon\CarbonInterface;

/**
 * What a slip writer is given for one file: the election's details, the file's place among the
 * parts of its group, and the slips in the order to print them.
 */
final readonly class SlipSheet
{
    /**
     * @param  string  $votingAddress  `{APP_URL}/vote/{election uuid}`, with no code in it
     * @param  string|null  $groupName  null for the voters with no group
     * @param  list<Slip>  $slips
     */
    public function __construct(
        public string $institutionName,
        public string $electionTitle,
        public string $votingAddress,
        public CarbonInterface $startsAt,
        public CarbonInterface $endsAt,
        public string $timezone,
        public string $language,
        public ?string $groupName,
        public int $part,
        public int $parts,
        public array $slips,
    ) {}
}

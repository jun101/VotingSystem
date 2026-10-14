<?php

namespace App\Support\Reports;

use Illuminate\Support\Carbon;

/**
 * What the results report says (docs/api/publication/GET-elections-{election}-report.md): plain values, no
 * model and no key. `ballots` is the output of `ComputeResults`; `full` says whether the counts are shown (the
 * public report of a `winners` display shows none) and `showCheck` whether the bulletin check line is.
 *
 * @phpstan-type Row array{id: string, first_name: string, last_name: string, party: string|null, votes: int, percentage: float}
 * @phpstan-type Ballot array{id: string, title: string, seats: int, scope: string, groups: list<array{id: string, name: string}>, eligible: int, voted: int, cast: int, blank: int, candidates: list<Row>, winners: list<string>, tie: array{candidates: list<string>, seats_left: int}|null, check: array{recorded: int, expected: int, ok: bool}, note: string|null}
 */
final class ResultsReport
{
    /**
     * @param  list<Ballot>  $ballots
     */
    public function __construct(
        public readonly string $institution,
        public readonly string $title,
        public readonly string $language,
        public readonly string $timezone,
        public readonly Carbon $startsAt,
        public readonly Carbon $endsAt,
        public readonly int $eligible,
        public readonly int $voted,
        public readonly float $percentage,
        public readonly bool $full,
        public readonly bool $showCheck,
        public readonly array $ballots,
    ) {}
}

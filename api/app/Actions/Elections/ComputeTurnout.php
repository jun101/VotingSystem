<?php

namespace App\Actions\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Models\Voting\Participation;
use Carbon\CarbonImmutable;
use DateTimeImmutable;
use DateTimeZone;
use Illuminate\Support\Facades\DB;
use Throwable;

/**
 * Turnout of an election (docs/api/turnout/GET-elections-{election}-turnout.md): who has voted, in
 * total, by group and over time. The numbers come from `participations` and the voters only; nothing
 * here reads `votes`, `vote_choices` or `vote_audit` (ballot secrecy). The tenant scope limits the
 * voters and groups; the election was bound through it.
 *
 * Stateless: the clock and the election are arguments.
 */
final class ComputeTurnout
{
    /** An election lasts at most this long for the hourly series to be the default. */
    private const HOURLY_UP_TO_SECONDS = 3 * 86400;

    /** A series never holds more buckets than this, whatever the dates. */
    private const MAX_BUCKETS = 5000;

    /**
     * @param  'hour'|'day'|null  $bucket  null for the default of the election's length
     * @return array{eligible: int, voted: int, percentage: float, by_group: list<array{group: array{id: string, name: string}|null, voters: int, voted: int}>, bucket: 'hour'|'day', over_time: list<array{at: string, voted: int, total: int}>}
     */
    public function __invoke(Election $election, ?string $bucket, ?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $electionId = $election->id;

        $eligible = Voter::query()->where('election_id', $electionId)->count();
        $voted = Participation::query()->where('election_id', $electionId)->count();

        $bucket ??= $election->ends_at->getTimestamp() - $election->starts_at->getTimestamp() <= self::HOURLY_UP_TO_SECONDS ? 'hour' : 'day';

        return [
            'eligible' => $eligible,
            'voted' => $voted,
            'percentage' => $eligible === 0 ? 0.0 : round($voted / $eligible * 100, 1),
            'by_group' => $this->byGroup($electionId),
            'bucket' => $bucket,
            'over_time' => $voted === 0 ? [] : $this->overTime($election, $bucket, $now),
        ];
    }

    /**
     * Groups by name (case and accents ignored), then the voters with no group when there are some.
     *
     * @return list<array{group: array{id: string, name: string}|null, voters: int, voted: int}>
     */
    private function byGroup(int $electionId): array
    {
        $lines = [];

        $groups = VoterGroup::query()
            ->where('voter_groups.election_id', $electionId)
            ->leftJoin('voters', 'voters.voter_group_id', '=', 'voter_groups.id')
            ->leftJoin('participations', 'participations.voter_id', '=', 'voters.id')
            ->groupBy('voter_groups.id', 'voter_groups.uuid', 'voter_groups.name')
            ->orderByRaw('voter_groups.name COLLATE utf8mb4_unicode_ci')
            ->orderBy('voter_groups.id')
            ->selectRaw('voter_groups.uuid as uuid, voter_groups.name as name, COUNT(voters.id) as voters, COUNT(participations.voter_id) as voted')
            ->toBase()
            ->get();

        foreach ($groups as $group) {
            $lines[] = [
                'group' => ['id' => self::text($group->uuid), 'name' => self::text($group->name)],
                'voters' => self::int($group->voters),
                'voted' => self::int($group->voted),
            ];
        }

        $none = Voter::query()
            ->where('voters.election_id', $electionId)
            ->whereNull('voters.voter_group_id')
            ->leftJoin('participations', 'participations.voter_id', '=', 'voters.id')
            ->selectRaw('COUNT(voters.id) as voters, COUNT(participations.voter_id) as voted')
            ->toBase()
            ->first();

        if ($none !== null && self::int($none->voters) > 0) {
            $lines[] = ['group' => null, 'voters' => self::int($none->voters), 'voted' => self::int($none->voted)];
        }

        return $lines;
    }

    /**
     * The buckets from the opening to the current one (or the end's), empty ones included, with the
     * running total. Votes are counted per minute in SQL, then put in buckets of the election's zone.
     *
     * @param  'hour'|'day'  $bucket
     * @return list<array{at: string, voted: int, total: int}>
     */
    private function overTime(Election $election, string $bucket, CarbonImmutable $now): array
    {
        $zone = $this->zoneOf($election);

        $minutes = Participation::query()
            ->where('election_id', $election->id)
            ->selectRaw("DATE_FORMAT(voted_at, '%Y-%m-%d %H:%i:00') as minute, COUNT(*) as total")
            ->groupBy(DB::raw('1'))
            ->orderBy(DB::raw('1'))
            ->toBase()
            ->get();

        /** @var array<int, int> $counts bucket start (UTC timestamp) => votes */
        $counts = [];

        foreach ($minutes as $row) {
            $minute = CarbonImmutable::createFromFormat('Y-m-d H:i:s', self::text($row->minute), 'UTC');

            if ($minute === null) {
                continue;
            }

            $key = $this->floor($minute->getTimestamp(), $bucket, $zone);
            $counts[$key] = ($counts[$key] ?? 0) + self::int($row->total);
        }

        if ($counts === []) {
            return [];
        }

        // From the opening, or the first vote if it came earlier; to the current bucket, or the end's
        // (the moment the election was closed, when that was earlier), or the last vote if it came later.
        $end = $election->ends_at->getTimestamp();

        if (in_array($election->status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
            $closed = $election->closed_at;
            $end = $closed !== null ? min($end, $closed->getTimestamp()) : $end;
        } else {
            $end = min($end, $now->getTimestamp());
        }

        $first = min($this->floor($election->starts_at->getTimestamp(), $bucket, $zone), min(array_keys($counts)));
        $last = max($this->floor($end, $bucket, $zone), max(array_keys($counts)));

        $series = [];
        $total = 0;
        $at = $first;

        for ($i = 0; $at <= $last && $i < self::MAX_BUCKETS; $i++) {
            $voted = $counts[$at] ?? 0;
            $total += $voted;
            $series[] = ['at' => gmdate('Y-m-d\TH:i:s\Z', $at), 'voted' => $voted, 'total' => $total];
            $at = $this->next($at, $bucket, $zone);
        }

        return $series;
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    private static function text(mixed $value): string
    {
        return is_string($value) ? $value : '';
    }

    /** The UTC timestamp of the start of the bucket an instant falls in, in the election's zone. */
    private function floor(int $timestamp, string $bucket, DateTimeZone $zone): int
    {
        if ($bucket === 'day') {
            return CarbonImmutable::createFromTimestampUTC($timestamp)->setTimezone($zone)->startOfDay()->getTimestamp();
        }

        // Hours of the zone's wall clock: a zone at +05:30 has its hours on the half.
        $offset = $zone->getOffset(new DateTimeImmutable('@'.$timestamp));

        return $timestamp - ((($timestamp + $offset) % 3600) + 3600) % 3600;
    }

    private function next(int $start, string $bucket, DateTimeZone $zone): int
    {
        if ($bucket === 'day') {
            return CarbonImmutable::createFromTimestampUTC($start)->setTimezone($zone)->addDay()->startOfDay()->getTimestamp();
        }

        return $start + 3600;
    }

    private function zoneOf(Election $election): DateTimeZone
    {
        try {
            return new DateTimeZone($election->timezone);
        } catch (Throwable) {
            return new DateTimeZone('UTC');
        }
    }
}

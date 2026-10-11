<?php

namespace App\Actions\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use App\Models\Voter;
use App\Support\Voting\VotePhase;
use Carbon\CarbonImmutable;

/**
 * What the institution's home page shows (docs/api/turnout/GET-dashboard.md): the elections by status,
 * the open ones with their turnout, the scheduled ones, and what needs doing. Only participations and
 * voters are counted: no vote count of any ballot. The tenant scope limits every query to the
 * signed-in institution.
 *
 * Stateless: the clock is an argument.
 */
final class ComputeDashboard
{
    /** The open elections, the scheduled ones and the drafts judged are at most this many each. */
    private const LIMIT = 10;

    public function __construct(private readonly ComputeChecklist $checklist) {}

    /**
     * @return array{counts: array<string, int>, voters: int, open: list<array<string, mixed>>, upcoming: list<array<string, mixed>>, todo: list<array<string, mixed>>}
     */
    public function __invoke(?CarbonImmutable $now = null): array
    {
        $now ??= CarbonImmutable::now('UTC');
        $iso = static fn (\DateTimeInterface $date): string => CarbonImmutable::instance($date)->utc()->format('Y-m-d\TH:i:s\Z');

        $counts = [];
        foreach (ElectionStatus::cases() as $case) {
            $counts[$case->value] = 0;
        }
        foreach (Election::query()->selectRaw('status, COUNT(*) as total')->groupBy('status')->toBase()->get() as $row) {
            if (is_string($row->status) && array_key_exists($row->status, $counts)) {
                $counts[$row->status] = self::int($row->total);
            }
        }

        // The status is not trusted alone: an election past its end is not open, whatever the scheduler did.
        /** @var list<Election> $open */
        $open = Election::query()
            ->where('status', ElectionStatus::Open->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->get(['id', 'uuid', 'title', 'status', 'starts_at', 'ends_at'])
            ->filter(fn (Election $election): bool => VotePhase::of($election->status, $election->starts_at, $election->ends_at, $now) === VotePhase::OPEN)
            ->values()
            ->all();

        $listed = array_slice($open, 0, self::LIMIT);
        $turnout = $this->turnout(array_map(fn (Election $election): int => $election->id, $open));

        $openCards = [];
        $notVoted = [];

        foreach ($listed as $election) {
            $line = $turnout[$election->id] ?? ['eligible' => 0, 'voted' => 0];
            $openCards[] = [
                'id' => $election->uuid,
                'title' => $election->title,
                'ends_at' => $iso($election->ends_at),
                'eligible' => $line['eligible'],
                'voted' => $line['voted'],
                'percentage' => $line['eligible'] === 0 ? 0.0 : round($line['voted'] / $line['eligible'] * 100, 1),
            ];

            if ($line['eligible'] - $line['voted'] > 0) {
                $notVoted[] = ['kind' => 'voters_not_voted', 'election' => ['id' => $election->uuid, 'title' => $election->title], 'count' => $line['eligible'] - $line['voted']];
            }
        }

        /** @var list<Election> $scheduled */
        $scheduled = Election::query()
            ->where('status', ElectionStatus::Scheduled->value)
            ->orderBy('starts_at')
            ->orderBy('id')
            ->get(['id', 'uuid', 'title', 'starts_at'])
            ->all();

        $upcoming = array_map(
            fn (Election $election): array => ['id' => $election->uuid, 'title' => $election->title, 'starts_at' => $iso($election->starts_at)],
            array_slice($scheduled, 0, self::LIMIT),
        );

        // Drafts with something that stops them from being scheduled.
        $draftTodo = [];

        $drafts = Election::query()
            ->where('status', ElectionStatus::Draft->value)
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->limit(self::LIMIT)
            ->get(['id', 'uuid', 'title', 'status']);

        foreach ($drafts as $draft) {
            $blockers = count(($this->checklist)($draft)['blockers']);

            if ($blockers > 0) {
                $draftTodo[] = ['kind' => 'draft_blockers', 'election' => ['id' => $draft->uuid, 'title' => $draft->title], 'count' => $blockers];
            }
        }

        // Voters with no code, in the scheduled elections and the open ones.
        $codeTodo = [];
        $withCodes = [...$listed, ...array_slice($scheduled, 0, self::LIMIT)];
        $missing = $this->codesMissing(array_map(fn (Election $election): int => $election->id, $withCodes));

        foreach ($withCodes as $election) {
            $count = $missing[$election->id] ?? 0;

            if ($count > 0) {
                $codeTodo[] = ['kind' => 'codes_missing', 'election' => ['id' => $election->uuid, 'title' => $election->title], 'count' => $count];
            }
        }

        return [
            'counts' => $counts,
            'voters' => Voter::query()->count(),
            'open' => $openCards,
            'upcoming' => $upcoming,
            'todo' => [...$notVoted, ...$draftTodo, ...$codeTodo],
        ];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }

    /**
     * Voters and voters who voted, per election, in one query.
     *
     * @param  list<int>  $electionIds
     * @return array<int, array{eligible: int, voted: int}>
     */
    private function turnout(array $electionIds): array
    {
        if ($electionIds === []) {
            return [];
        }

        $rows = Voter::query()
            ->whereIn('voters.election_id', $electionIds)
            ->leftJoin('participations', 'participations.voter_id', '=', 'voters.id')
            ->groupBy('voters.election_id')
            ->selectRaw('voters.election_id as election, COUNT(voters.id) as eligible, COUNT(participations.voter_id) as voted')
            ->toBase()
            ->get();

        $lines = [];

        foreach ($rows as $row) {
            $lines[self::int($row->election)] = ['eligible' => self::int($row->eligible), 'voted' => self::int($row->voted)];
        }

        return $lines;
    }

    /**
     * Voters with no current code, per election.
     *
     * @param  list<int>  $electionIds
     * @return array<int, int>
     */
    private function codesMissing(array $electionIds): array
    {
        if ($electionIds === []) {
            return [];
        }

        $rows = Voter::query()
            ->whereIn('election_id', $electionIds)
            ->whereDoesntHave('credential')
            ->groupBy('election_id')
            ->selectRaw('election_id as election, COUNT(*) as total')
            ->toBase()
            ->get();

        $lines = [];

        foreach ($rows as $row) {
            $lines[self::int($row->election)] = self::int($row->total);
        }

        return $lines;
    }
}

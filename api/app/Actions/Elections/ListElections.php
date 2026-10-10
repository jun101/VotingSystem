<?php

namespace App\Actions\Elections;

use App\Enums\ElectionStatus;
use App\Models\Election;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Throwable;

/**
 * The list of docs/api/elections/GET-elections.md. The tenant scope limits every query to the
 * signed-in user's institution.
 *
 * The year of a start is read in the election's own time zone, in PHP: the database is never
 * asked to convert a zone (its zone tables may be missing). The distinct (zone, start) pairs of
 * the institution give the years of the filter bar, and the zones present give the bounds of a
 * year filter, one range of UTC instants per zone.
 */
final class ListElections
{
    /**
     * @return array{page: LengthAwarePaginator<int, Election>, counts: array<string, int>, years: list<int>}
     */
    public function __invoke(?ElectionStatus $status, ?int $year, int $perPage, int $page): array
    {
        $starts = Election::query()->distinct()->get(['timezone', 'starts_at']);

        $query = Election::query()->withCount(['ballots', 'voters']);

        if ($status !== null) {
            $query->where('status', $status->value);
        } else {
            $query->where('status', '!=', ElectionStatus::Archived->value);
        }

        if ($year !== null) {
            $zones = [];
            foreach ($starts as $election) {
                $zones[$election->timezone] = $election->timezone;
            }
            $this->limitToYear($query, $year, array_values($zones));
        }

        // Open first, archived last: the position of the status in this list.
        $ranked = ElectionStatus::cases();
        usort($ranked, fn (ElectionStatus $a, ElectionStatus $b): int => $a->listRank() <=> $b->listRank());

        $paginated = $query
            ->orderByRaw('FIELD(status, ?, ?, ?, ?, ?, ?)', array_map(fn (ElectionStatus $status): string => $status->value, $ranked))
            ->orderByDesc('starts_at')
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage, page: $page);

        return ['page' => $paginated, 'counts' => $this->counts(), 'years' => $this->years($starts)];
    }

    /**
     * @param  Builder<Election>  $query
     * @param  list<string>  $zones
     */
    private function limitToYear(Builder $query, int $year, array $zones): void
    {
        $query->where(function (Builder $any) use ($year, $zones): void {
            // Without a zone there is no election: nothing matches.
            $any->whereRaw('0 = 1');

            foreach ($zones as $zone) {
                try {
                    $from = CarbonImmutable::create($year, 1, 1, 0, 0, 0, $zone);
                    $to = CarbonImmutable::create($year + 1, 1, 1, 0, 0, 0, $zone);
                } catch (Throwable) {
                    continue;
                }

                if ($from === null || $to === null) {
                    continue;
                }

                $from = $from->utc();
                $to = $to->utc();

                $any->orWhere(function (Builder $inZone) use ($zone, $from, $to): void {
                    $inZone->where('timezone', $zone)
                        ->where('starts_at', '>=', $from->format('Y-m-d H:i:s'))
                        ->where('starts_at', '<', $to->format('Y-m-d H:i:s'));
                });
            }
        });
    }

    /**
     * One count per status, whatever the filters; `all` leaves the archived ones out.
     *
     * @return array<string, int>
     */
    private function counts(): array
    {
        $grouped = [];
        foreach (Election::query()->selectRaw('status, COUNT(*) AS total')->groupBy('status')->toBase()->get() as $row) {
            if (is_string($row->status) && is_numeric($row->total)) {
                $grouped[$row->status] = (int) $row->total;
            }
        }

        $counts = ['all' => 0];
        foreach (ElectionStatus::cases() as $case) {
            $counts[$case->value] = $grouped[$case->value] ?? 0;
        }
        $counts['all'] = array_sum($counts) - $counts[ElectionStatus::Archived->value];

        return $counts;
    }

    /**
     * The years of all the institution's elections, newest first.
     *
     * @param  iterable<int, Election>  $starts
     * @return list<int>
     */
    private function years(iterable $starts): array
    {
        $years = [];

        foreach ($starts as $election) {
            try {
                $years[$election->starts_at->copy()->setTimezone($election->timezone)->year] = true;
            } catch (Throwable) {
                $years[$election->starts_at->year] = true;
            }
        }

        $list = array_keys($years);
        rsort($list);

        return $list;
    }
}

<?php

namespace App\Actions\Credentials;

use App\Exceptions\ApiException;
use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\User;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\PartSplitter;
use App\Support\Credentials\RowCount;
use Illuminate\Database\Query\JoinClause;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Throwable;

/**
 * The request side of a run: under the election's row lock it judges the state, counts who is
 * served and who already has a code, and creates the `queued` file rows, one per group with
 * voters to serve and per part of at most 1 000. The codes and the PDFs are written by the job.
 */
final class StartCredentialRun
{
    /** A file still `queued` after this many minutes is taken for lost when a run starts. */
    public const STALE_MINUTES = 15;

    /**
     * @param  'all'|'group'|'ungrouped'  $scope
     * @return array{files: list<GeneratedFile>, voters: int, skipped: int}
     *
     * @throws ApiException 404, or 409 `election_codes_locked`, `run_in_progress`, `nothing_to_generate`
     */
    public function __invoke(Election $election, string $scope, ?VoterGroup $group, ?User $user): array
    {
        $stale = [];

        $run = DB::transaction(function () use ($election, $scope, $group, $user, &$stale): array {
            // Locked first, as every write of the voters and codes of an election.
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            $locked->assertCodesOpen();

            $stale = $this->failStale($locked);

            if (GeneratedFile::query()
                ->where('election_id', $locked->id)
                ->where('status', GeneratedFile::STATUS_QUEUED)
                ->exists()) {
                throw new ApiException(409, 'run_in_progress');
            }

            $counts = $this->counts($locked, $scope, $group);
            $skipped = array_sum(array_column($counts, 'coded'));
            $toServe = 0;

            foreach ($counts as $row) {
                $toServe += $row['total'] - $row['coded'];
            }

            if ($toServe === 0) {
                throw new ApiException(409, 'nothing_to_generate');
            }

            $files = [];

            foreach ($this->groupsToServe($locked, $counts) as [$serving, $left]) {
                $sizes = PartSplitter::sizes($left);

                foreach ($sizes as $index => $size) {
                    $file = new GeneratedFile;
                    $file->institution_id = $locked->institution_id;
                    $file->election_id = $locked->id;
                    $file->user_id = $user?->id;
                    $file->kind = GeneratedFile::KIND_SLIPS;
                    $file->voter_group_id = $serving?->id;
                    $file->part = $index + 1;
                    $file->parts = count($sizes);
                    $file->voter_count = $size;
                    $file->status = GeneratedFile::STATUS_QUEUED;
                    $file->failure = null;
                    $file->expires_at = $locked->ends_at;
                    $file->save();
                    $file->setRelation('group', $serving);
                    $file->setRelation('election', $locked);

                    $files[] = $file;
                }
            }

            return ['files' => $files, 'voters' => $toServe, 'skipped' => $skipped];
        });

        // After the commit: the copies a lost job had claimed.
        foreach ($stale as $name) {
            try {
                Storage::disk('credentials')->delete($name);
            } catch (Throwable) {
                // An orphan under a random name at worst.
            }
        }

        return $run;
    }

    /**
     * Marks `failed` the files of the election still `queued` after 15 minutes: a lost job must
     * not block the election. The election's row is locked by the caller.
     *
     * @return list<string> the stored names to remove after the commit
     */
    private function failStale(Election $election): array
    {
        $old = GeneratedFile::query()
            ->where('election_id', $election->id)
            ->where('status', GeneratedFile::STATUS_QUEUED)
            ->where('created_at', '<', Carbon::now('UTC')->subMinutes(self::STALE_MINUTES))
            ->get(['id', 'stored_file']);

        if ($old->isEmpty()) {
            return [];
        }

        GeneratedFile::query()->whereIn('id', $old->pluck('id'))->update([
            'status' => GeneratedFile::STATUS_FAILED,
            'failure' => GeneratedFile::FAILURE_GENERATION,
            'stored_file' => null,
            'updated_at' => Carbon::now('UTC'),
        ]);

        return array_values(array_filter($old->pluck('stored_file')->all(), is_string(...)));
    }

    /**
     * Voters and voters with a code, by group id (key 0 for the voters with no group).
     *
     * @return array<int, array{total: int, coded: int}>
     */
    private function counts(Election $election, string $scope, ?VoterGroup $group): array
    {
        $query = Voter::query()
            ->where('voters.election_id', $election->id)
            ->leftJoin('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->selectRaw('voters.voter_group_id as group_key, count(*) as total, count(credentials.id) as coded')
            ->groupBy('voters.voter_group_id');

        if ($scope === 'group' && $group !== null) {
            $query->where('voters.voter_group_id', $group->id);
        } elseif ($scope === 'ungrouped') {
            $query->whereNull('voters.voter_group_id');
        }

        $counts = [];

        foreach ($query->get() as $row) {
            $counts[RowCount::of($row, 'group_key')] = [
                'total' => RowCount::of($row, 'total'),
                'coded' => RowCount::of($row, 'coded'),
            ];
        }

        return $counts;
    }

    /**
     * The groups that have voters to serve, by name, the voters with no group last.
     *
     * @param  array<int, array{total: int, coded: int}>  $counts
     * @return list<array{0: VoterGroup|null, 1: int}>
     */
    private function groupsToServe(Election $election, array $counts): array
    {
        $plan = [];
        $ids = array_filter(array_keys($counts), fn (int $key): bool => $key !== 0);

        if ($ids !== []) {
            $groups = VoterGroup::query()
                ->where('election_id', $election->id)
                ->whereIn('id', $ids)
                ->orderBy('name')
                ->orderBy('id')
                ->get();

            foreach ($groups as $group) {
                $left = $counts[$group->id]['total'] - $counts[$group->id]['coded'];

                if ($left > 0) {
                    $plan[] = [$group, $left];
                }
            }
        }

        if (isset($counts[0]) && $counts[0]['total'] - $counts[0]['coded'] > 0) {
            $plan[] = [null, $counts[0]['total'] - $counts[0]['coded']];
        }

        return $plan;
    }
}

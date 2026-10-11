<?php

namespace App\Actions\Credentials;

use App\Models\Election;
use App\Models\GeneratedFile;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Credentials\RowCount;
use Illuminate\Database\Query\JoinClause;

/**
 * The state of the access codes of an election for screen A11: totals and one line per group,
 * the voters with no group last. Counts only; a code or its hash is never read.
 */
final class CredentialState
{
    /**
     * @return array{voters: int, with_code: int, without_code: int, with_email: int, email_queued: int, email_sent: int, email_failed: int, run_in_progress: bool, groups: list<array{group: array{id: string, name: string}|null, voters: int, with_code: int, with_email: int, email_queued: int, email_sent: int, email_failed: int, files_ready: int}>}
     */
    public function __invoke(Election $election): array
    {
        // Voters, voters with a code and voters with an email, by group id (0: no group).
        $perGroup = [];

        $rows = Voter::query()
            ->where('voters.election_id', $election->id)
            ->leftJoin('credentials', function (JoinClause $join): void {
                $join->on('credentials.voter_id', '=', 'voters.id')
                    ->on('credentials.institution_id', '=', 'voters.institution_id');
            })
            ->selectRaw('voters.voter_group_id as group_key, count(*) as voters, count(credentials.id) as with_code, sum(voters.email is not null) as with_email, sum(credentials.email_status = \'queued\') as email_queued, sum(credentials.email_status = \'sent\') as email_sent, sum(credentials.email_status = \'failed\' and voters.email is not null) as email_failed')
            ->groupBy('voters.voter_group_id')
            ->get();

        foreach ($rows as $row) {
            $perGroup[RowCount::of($row, 'group_key')] = [
                'voters' => RowCount::of($row, 'voters'),
                'with_code' => RowCount::of($row, 'with_code'),
                'with_email' => RowCount::of($row, 'with_email'),
                'email_queued' => RowCount::of($row, 'email_queued'),
                'email_sent' => RowCount::of($row, 'email_sent'),
                'email_failed' => RowCount::of($row, 'email_failed'),
            ];
        }

        // The files are deleted when the election closes: none is counted ready after.
        $ready = [];

        if ($election->isCodesOpen()) {
            $files = GeneratedFile::query()
                ->where('election_id', $election->id)
                ->where('status', GeneratedFile::STATUS_READY)
                ->where('kind', GeneratedFile::KIND_SLIPS)
                ->selectRaw('voter_group_id as group_key, count(*) as ready')
                ->groupBy('voter_group_id')
                ->get();

            foreach ($files as $row) {
                $ready[RowCount::of($row, 'group_key')] = RowCount::of($row, 'ready');
            }
        }

        $none = ['voters' => 0, 'with_code' => 0, 'with_email' => 0, 'email_queued' => 0, 'email_sent' => 0, 'email_failed' => 0];
        $groups = [];

        foreach (VoterGroup::query()->where('election_id', $election->id)->orderBy('name')->orderBy('id')->get() as $group) {
            $counts = $perGroup[$group->id] ?? $none;
            $groups[] = ['group' => ['id' => $group->uuid, 'name' => $group->name]] + $counts + ['files_ready' => $ready[$group->id] ?? 0];
        }

        if (isset($perGroup[0])) {
            $groups[] = ['group' => null] + $perGroup[0] + ['files_ready' => $ready[0] ?? 0];
        }

        $totals = $none;

        foreach ($perGroup as $counts) {
            $totals['voters'] += $counts['voters'];
            $totals['with_code'] += $counts['with_code'];
            $totals['with_email'] += $counts['with_email'];
            $totals['email_queued'] += $counts['email_queued'];
            $totals['email_sent'] += $counts['email_sent'];
            $totals['email_failed'] += $counts['email_failed'];
        }

        $queued = GeneratedFile::query()
            ->where('election_id', $election->id)
            ->where('status', GeneratedFile::STATUS_QUEUED)
            ->exists();

        return [
            'voters' => $totals['voters'],
            'with_code' => $totals['with_code'],
            'without_code' => $totals['voters'] - $totals['with_code'],
            'with_email' => $totals['with_email'],
            'email_queued' => $totals['email_queued'],
            'email_sent' => $totals['email_sent'],
            'email_failed' => $totals['email_failed'],
            // A file is queued, or a message of the run is still waiting to be sent.
            'run_in_progress' => $queued || $totals['email_queued'] > 0,
            'groups' => $groups,
        ];
    }
}

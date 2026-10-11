<?php

namespace App\Actions\Elections;

use App\Enums\BallotScope;
use App\Enums\ElectionStatus;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Voter;
use App\Models\VoterGroup;
use Illuminate\Support\Facades\DB;

/**
 * The checklist of an election (docs/api/elections/GET-elections-{election}-checklist.md): what stops
 * it from being scheduled and what to look at first. A handful of queries, whatever the number of
 * ballots. Items come in a fixed order: by code, then by ballot position.
 */
final class ComputeChecklist
{
    /**
     * @return array{status: string, can_schedule: bool, counts: array{ballots: int, candidates: int, voters: int, groups: int}, blockers: list<array<string, mixed>>, warnings: list<array<string, mixed>>}
     */
    public function __invoke(Election $election): array
    {
        $electionId = $election->getKey();

        /** @var list<Ballot> $ballots */
        $ballots = Ballot::query()->where('election_id', $electionId)->orderBy('position')->orderBy('id')->get(['id', 'uuid', 'title', 'scope'])->all();
        $ballotIds = array_map(fn (Ballot $ballot): int => $ballot->id, $ballots);

        // Candidates per ballot.
        /** @var array<int, int> $candidates */
        $candidates = [];

        if ($ballotIds !== []) {
            $candidates = Candidate::query()
                ->whereIn('ballot_id', $ballotIds)
                ->selectRaw('ballot_id, count(*) as total')
                ->groupBy('ballot_id')
                ->pluck('total', 'ballot_id')
                ->map(fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
                ->all();
        }

        // Voters: the total, and per group.
        $voters = Voter::query()->where('election_id', $electionId)->count();
        /** @var array<int, int> $perGroup */
        $perGroup = Voter::query()
            ->where('election_id', $electionId)
            ->whereNotNull('voter_group_id')
            ->selectRaw('voter_group_id, count(*) as total')
            ->groupBy('voter_group_id')
            ->pluck('total', 'voter_group_id')
            ->map(fn (mixed $total): int => is_numeric($total) ? (int) $total : 0)
            ->all();
        $groups = VoterGroup::query()->where('election_id', $electionId)->count();

        // The groups each group ballot covers.
        /** @var array<int, list<int>> $covered */
        $covered = [];

        if ($ballotIds !== []) {
            // A pivot table with no model: its rows are read for ballots of this election only.
            $rows = DB::table('ballot_voter_group')->whereIn('ballot_id', $ballotIds)->get(['ballot_id', 'voter_group_id']);

            foreach ($rows as $row) {
                $ballotId = $row->ballot_id;
                $groupId = $row->voter_group_id;

                if (is_numeric($ballotId) && is_numeric($groupId)) {
                    $covered[(int) $ballotId][] = (int) $groupId;
                }
            }
        }

        $blockers = [];
        $warnings = [];
        $single = [];
        $noVoters = [];
        $fewVoters = [];
        $hasGeneral = false;
        $coveredGroups = [];

        foreach ($ballots as $ballot) {
            $ref = ['id' => $ballot->uuid, 'title' => $ballot->title];
            $count = $candidates[$ballot->id] ?? 0;

            if ($count === 0) {
                $blockers[] = ['code' => 'ballot_without_candidates', 'ballot' => $ref];
            } elseif ($count === 1) {
                $single[] = ['code' => 'ballot_single_candidate', 'ballot' => $ref];
            }

            if ($ballot->scope === BallotScope::Group) {
                $groupIds = $covered[$ballot->id] ?? [];
                $eligible = 0;

                foreach ($groupIds as $groupId) {
                    $eligible += $perGroup[$groupId] ?? 0;
                    $coveredGroups[$groupId] = true;
                }

                if ($eligible === 0) {
                    $noVoters[] = ['code' => 'group_ballot_no_voters', 'ballot' => $ref];
                } elseif ($eligible < 5) {
                    $fewVoters[] = ['code' => 'group_ballot_few_voters', 'ballot' => $ref, 'count' => $eligible];
                }
            } else {
                $hasGeneral = true;
            }
        }

        $head = [];

        if ($ballots === []) {
            $head[] = ['code' => 'no_ballots'];
        }

        // The ballot blockers come after `no_ballots` (which excludes them) and before `no_voters`.
        $blockers = [...$head, ...$blockers];

        if ($voters === 0) {
            $blockers[] = ['code' => 'no_voters'];
        }

        $warnings = [...$single, ...$noVoters, ...$fewVoters];

        // Voters eligible for no ballot: none when a general ballot exists; with no ballot at all the
        // blocker `no_ballots` says it.
        if ($ballots !== [] && ! $hasGeneral) {
            $eligible = array_sum(array_intersect_key($perGroup, $coveredGroups));
            $without = $voters - $eligible;

            if ($without > 0) {
                $warnings[] = ['code' => 'voters_without_ballot', 'count' => $without];
            }
        }

        return [
            'status' => $election->status->value,
            'can_schedule' => $election->status === ElectionStatus::Draft && $blockers === [],
            'counts' => [
                'ballots' => count($ballots),
                'candidates' => array_sum($candidates),
                'voters' => $voters,
                'groups' => $groups,
            ],
            'blockers' => $blockers,
            'warnings' => $warnings,
        ];
    }
}

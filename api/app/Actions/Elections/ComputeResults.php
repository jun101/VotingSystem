<?php

namespace App\Actions\Elections;

use App\Enums\BallotScope;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Voter;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The results of a closed election (docs/api/results/GET-elections-{election}-results.md): per
 * ballot the papers cast, the blank ones, the votes of each candidate, the winners, the tie for
 * the last seats and the bulletin check.
 *
 * Read from `votes` and `vote_choices` (counted in SQL, one query each for the whole election) and
 * from the voters and their participations. `vote_audit` is never read: the link between a vote
 * and its voter does not exist here. The election was bound through the tenant scope.
 *
 * Stateless: the election is an argument.
 */
final class ComputeResults
{
    /**
     * @param  Ballot|null  $only  one ballot of the election, for the second round
     * @param  bool  $unscoped  for the public pages (nobody is signed in): the tenant scope is off; the election itself was found by its uuid and published, and every query below is limited to it
     * @return list<array{id: string, title: string, seats: int, scope: string, groups: list<array{id: string, name: string}>, eligible: int, voted: int, cast: int, blank: int, candidates: list<array{id: string, first_name: string, last_name: string, party: string|null, votes: int, percentage: float}>, winners: list<string>, tie: array{candidates: list<string>, seats_left: int}|null, check: array{recorded: int, expected: int, ok: bool}, note: string|null}>
     */
    public function __invoke(Election $election, ?Ballot $only = null, bool $unscoped = false): array
    {
        $electionId = $election->id;

        // Off only for the public pages, where no institution user is signed in and the scope would match
        // nothing: the election was found by its uuid and its status, and the query is limited to it.
        $ballotQuery = $unscoped ? Ballot::withoutInstitutionScope() : Ballot::query();

        $ballots = $ballotQuery
            ->where('election_id', $electionId)
            ->when($only !== null, fn ($query) => $query->whereKey($only?->getKey()))
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        if ($ballots->isEmpty()) {
            return [];
        }

        // The group ballots' groups, by name then creation order: read through the pivot table, limited to the
        // ballots of this election, so the same query serves the signed-in user and the public pages.
        $groupRows = [];

        foreach (DB::table('ballot_voter_group')
            ->join('voter_groups', 'voter_groups.id', '=', 'ballot_voter_group.voter_group_id')
            ->whereIn('ballot_voter_group.ballot_id', $ballots->pluck('id')->all())
            ->orderBy('voter_groups.name_key')
            ->orderBy('voter_groups.id')
            ->get(['ballot_voter_group.ballot_id as ballot', 'voter_groups.id as group', 'voter_groups.uuid as uuid', 'voter_groups.name as name']) as $row) {
            $groupRows[self::int($row->ballot)][] = ['key' => self::int($row->group), 'uuid' => is_string($row->uuid) ? $row->uuid : '', 'name' => is_string($row->name) ? $row->name : ''];
        }

        // As above for the candidates of the election.
        $candidateQuery = $unscoped ? Candidate::withoutInstitutionScope() : Candidate::query();

        $candidates = $candidateQuery
            ->where('election_id', $electionId)
            ->orderBy('ballot_id')
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        // The names of the parties the candidates stand for, whoever is asking.
        $partyNames = [];
        $partyKeys = $candidates->pluck('party_id')->filter()->unique()->values()->all();

        if ($partyKeys !== []) {
            foreach (DB::table('parties')->where('election_id', $electionId)->whereIn('id', $partyKeys)->get(['id', 'name']) as $row) {
                $partyNames[self::int($row->id)] = is_string($row->name) ? $row->name : null;
            }
        }

        $candidates = $candidates->groupBy('ballot_id');

        $cast = $this->cast($electionId);
        $blank = $this->blank($electionId);
        $votes = $this->votesPerCandidate($electionId);
        [$voters, $voted] = $this->votersPerGroup($electionId, $unscoped);

        $out = [];

        // The second rounds already created: ballot key => the new election's uuid. Read without the tenant scope,
        // but only for ballots this election's own (scoped) query returned.
        $rounds = DB::table('ballots')
            ->join('elections', 'elections.id', '=', 'ballots.election_id')
            ->whereIn('ballots.source_ballot_id', $ballots->pluck('id')->all())
            ->pluck('elections.uuid', 'ballots.source_ballot_id')
            ->all();

        foreach ($ballots as $ballot) {
            $scoped = $ballot->scope === BallotScope::Group;
            $groupIds = [];
            $named = [];

            foreach ($groupRows[$ballot->id] ?? [] as $group) {
                $groupIds[] = $group['key'];
                $named[] = ['id' => $group['uuid'], 'name' => $group['name']];
            }

            $eligible = 0;
            $participated = 0;

            foreach ($voters as $group => $count) {
                // A general ballot is for everybody; a group ballot for the voters of its groups.
                if (! $scoped || ($group !== 0 && in_array($group, $groupIds, true))) {
                    $eligible += $count;
                    $participated += $voted[$group] ?? 0;
                }
            }

            $papers = $cast[$ballot->id] ?? 0;
            /** @var list<array{uuid: string, first_name: string, last_name: string, party: string|null, votes: int}> $rows */
            $rows = [];

            /** @var Collection<int, Candidate> $standing */
            $standing = $candidates->get($ballot->id, new Collection);

            foreach ($standing as $candidate) {
                $rows[] = [
                    'uuid' => $candidate->uuid,
                    'first_name' => $candidate->first_name,
                    'last_name' => $candidate->last_name,
                    'party' => $candidate->party_id === null ? null : ($partyNames[(int) $candidate->party_id] ?? null),
                    'votes' => $votes[$candidate->id] ?? 0,
                ];
            }

            // Most votes first; equal votes keep the election's order (the sort is stable).
            usort($rows, fn (array $a, array $b): int => $b['votes'] <=> $a['votes']);

            [$winners, $tie] = $this->seats($rows, $ballot->seats);

            $shown = [];

            foreach ($rows as $row) {
                $shown[] = [
                    'id' => $row['uuid'],
                    'first_name' => $row['first_name'],
                    'last_name' => $row['last_name'],
                    'party' => $row['party'],
                    'votes' => $row['votes'],
                    'percentage' => ComputeTurnout::percentage($row['votes'], $papers),
                ];
            }

            $out[] = [
                'id' => $ballot->uuid,
                'title' => $ballot->title,
                'seats' => $ballot->seats,
                'scope' => $ballot->scope->value,
                'groups' => $scoped ? $named : [],
                'eligible' => $eligible,
                'voted' => $participated,
                'cast' => $papers,
                'blank' => $blank[$ballot->id] ?? 0,
                'candidates' => $shown,
                'winners' => $winners,
                'tie' => $tie,
                'check' => ['recorded' => $papers, 'expected' => $participated, 'ok' => $papers === $participated],
                'note' => $ballot->result_note,
                'second_round' => isset($rounds[$ballot->id]) && is_string($rounds[$ballot->id]) ? $rounds[$ballot->id] : null,
            ];
        }

        return $out;
    }

    /**
     * The seats: the best candidates win; a candidate with no vote never wins nor ties. When
     * candidates tie for the last open seats, only those above the tie win and the tie lists the others.
     *
     * @param  list<array{uuid: string, first_name: string, last_name: string, party: string|null, votes: int}>  $rows  sorted by votes, descending
     * @return array{0: list<string>, 1: array{candidates: list<string>, seats_left: int}|null}
     */
    private function seats(array $rows, int $seats): array
    {
        $voted = [];

        foreach ($rows as $row) {
            if ($row['votes'] > 0) {
                $voted[] = $row;
            }
        }

        if (count($voted) <= $seats) {
            return [$this->uuids($voted), null];
        }

        $cutoff = $voted[$seats - 1]['votes'];

        // The first one left out has fewer votes: no tie for the last seat.
        if ($voted[$seats]['votes'] < $cutoff) {
            return [$this->uuids(array_slice($voted, 0, $seats)), null];
        }

        $above = [];
        $tied = [];

        foreach ($voted as $row) {
            if ($row['votes'] > $cutoff) {
                $above[] = $row;
            } elseif ($row['votes'] === $cutoff) {
                $tied[] = $row;
            }
        }

        return [
            $this->uuids($above),
            ['candidates' => $this->uuids($tied), 'seats_left' => $seats - count($above)],
        ];
    }

    /**
     * @param  list<array{uuid: string, first_name: string, last_name: string, party: string|null, votes: int}>  $rows
     * @return list<string>
     */
    private function uuids(array $rows): array
    {
        $uuids = [];

        foreach ($rows as $row) {
            $uuids[] = $row['uuid'];
        }

        return $uuids;
    }

    /** @return array<int, int> ballot key => papers */
    private function cast(int $electionId): array
    {
        $counts = [];

        foreach (DB::table('votes')->where('election_id', $electionId)->groupBy('ballot_id')->selectRaw('ballot_id, COUNT(*) as total')->get() as $row) {
            $counts[self::int($row->ballot_id)] = self::int($row->total);
        }

        return $counts;
    }

    /** @return array<int, int> ballot key => papers with no choice */
    private function blank(int $electionId): array
    {
        $counts = [];

        $rows = DB::table('votes')
            ->leftJoin('vote_choices', 'vote_choices.vote_uuid', '=', 'votes.uuid')
            ->where('votes.election_id', $electionId)
            ->whereNull('vote_choices.vote_uuid')
            ->groupBy('votes.ballot_id')
            ->selectRaw('votes.ballot_id as ballot_id, COUNT(*) as total')
            ->get();

        foreach ($rows as $row) {
            $counts[self::int($row->ballot_id)] = self::int($row->total);
        }

        return $counts;
    }

    /** @return array<int, int> candidate key => papers it was ticked on */
    private function votesPerCandidate(int $electionId): array
    {
        $counts = [];

        $rows = DB::table('vote_choices')
            ->join('votes', 'votes.uuid', '=', 'vote_choices.vote_uuid')
            ->where('votes.election_id', $electionId)
            ->groupBy('vote_choices.candidate_id')
            ->selectRaw('vote_choices.candidate_id as candidate_id, COUNT(*) as total')
            ->get();

        foreach ($rows as $row) {
            $counts[self::int($row->candidate_id)] = self::int($row->total);
        }

        return $counts;
    }

    /**
     * The voters and those who voted, by group key (0 for no group).
     *
     * @return array{0: array<int, int>, 1: array<int, int>}
     */
    private function votersPerGroup(int $electionId, bool $unscoped): array
    {
        $voters = [];
        $voted = [];

        // Off only for the public pages, as above: the voters counted are those of the one election.
        $rows = ($unscoped ? Voter::withoutInstitutionScope() : Voter::query())
            ->where('voters.election_id', $electionId)
            ->leftJoin('participations', 'participations.voter_id', '=', 'voters.id')
            ->groupBy('voters.voter_group_id')
            ->selectRaw('voters.voter_group_id as voter_group_id, COUNT(voters.id) as voters, COUNT(participations.voter_id) as voted')
            ->toBase()
            ->get();

        foreach ($rows as $row) {
            $group = self::int($row->voter_group_id);
            $voters[$group] = self::int($row->voters);
            $voted[$group] = self::int($row->voted);
        }

        return [$voters, $voted];
    }

    private static function int(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : 0;
    }
}

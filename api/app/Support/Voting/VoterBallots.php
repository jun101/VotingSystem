<?php

namespace App\Support\Voting;

use App\Enums\BallotScope;
use App\Enums\CandidateOrder;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Party;
use App\Models\Voter;
use App\Support\Media\ImageReEncoder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

/**
 * The ballots of an election a voter may fill, with their candidates (FR-BAL-05, FR-VOTE-03).
 *
 * Every read here runs for a voter, not for a signed-in institution user: the tenant scope matches
 * nothing without one, and removes itself by name where it must. The election and the voter were
 * already found by the voter's own session, so nothing of another election is reachable: every
 * query below is limited to the election's key.
 *
 * Stateless: the election, the voter and the session's seed are passed in.
 */
final class VoterBallots
{
    /**
     * The ballots the voter is eligible for: every general ballot and the group ballots that name
     * the voter's group, in the election's order.
     *
     * @return Collection<int, Ballot>
     */
    public function eligible(Election $election, Voter $voter): Collection
    {
        // A voter answers for one election and has no signed-in institution user: the scope would match nothing.
        $query = Ballot::withoutInstitutionScope()->where('election_id', $election->id);

        $query->where(function ($which) use ($voter): void {
            $which->where('scope', BallotScope::General->value);

            if ($voter->voter_group_id !== null) {
                $which->orWhereIn('id', DB::table('ballot_voter_group')->where('voter_group_id', $voter->voter_group_id)->select('ballot_id'));
            }
        });

        return $query->orderBy('position')->orderBy('id')->get();
    }

    /**
     * The ballots as the voter sees them (docs/api/vote/GET-vote-{election}-ballots.md).
     *
     * @return list<array<string, mixed>>
     */
    public function present(Election $election, Voter $voter, string $seed): array
    {
        $ballots = $this->eligible($election, $voter);
        $keys = $ballots->pluck('id')->all();

        if ($keys === []) {
            return [];
        }

        // The candidates of the ballots of this election, for a voter without an institution user.
        $candidates = Candidate::withoutInstitutionScope()
            ->where('election_id', $election->id)
            ->whereIn('ballot_id', $keys)
            ->orderBy('position')->orderBy('id')
            ->get();

        $partyKeys = $candidates->pluck('party_id')->filter()->unique()->values()->all();
        // The parties named by those candidates, for a voter without an institution user.
        /** @var Collection<int|string, Party> $parties */
        $parties = $partyKeys === [] ? collect() : Party::withoutInstitutionScope()
            ->where('election_id', $election->id)
            ->whereIn('id', $partyKeys)
            ->get()->keyBy('id');

        $groupNames = $this->groupNames($keys);
        $shuffled = $election->candidate_order === CandidateOrder::Shuffled;
        $presented = [];

        foreach ($ballots as $ballot) {
            $list = $candidates->where('ballot_id', $ballot->id)->values();

            if ($shuffled) {
                $list = $list->sortBy(fn (Candidate $candidate): string => hash('sha256', $seed.'|'.$ballot->uuid.'|'.$candidate->uuid))->values();
            }

            $presented[] = [
                'id' => $ballot->uuid,
                'title' => $ballot->title,
                'description' => $ballot->description,
                'scope' => $ballot->scope->value,
                'group_names' => $ballot->scope === BallotScope::Group ? ($groupNames[$ballot->id] ?? []) : [],
                'seats' => $ballot->seats,
                'allow_blank' => $ballot->allow_blank,
                'candidates' => $list->map(fn (Candidate $candidate): array => $this->candidate($candidate, $parties))->all(),
            ];
        }

        return $presented;
    }

    /**
     * The groups of each group ballot, by name.
     *
     * @param  array<array-key, mixed>  $ballotKeys
     * @return array<int, list<string>>
     */
    private function groupNames(array $ballotKeys): array
    {
        $rows = DB::table('ballot_voter_group')
            ->join('voter_groups', 'voter_groups.id', '=', 'ballot_voter_group.voter_group_id')
            ->whereIn('ballot_voter_group.ballot_id', $ballotKeys)
            ->orderBy('voter_groups.name_key')
            ->get(['ballot_voter_group.ballot_id as ballot', 'voter_groups.name as name']);

        $names = [];

        foreach ($rows as $row) {
            if (is_numeric($row->ballot) && is_string($row->name)) {
                $names[(int) $row->ballot][] = $row->name;
            }
        }

        return $names;
    }

    /**
     * @param  Collection<int|string, Party>  $parties
     * @return array<string, mixed>
     */
    private function candidate(Candidate $candidate, Collection $parties): array
    {
        $party = $candidate->party_id === null ? null : $parties->get($candidate->party_id);

        return [
            'id' => $candidate->uuid,
            'first_name' => $candidate->first_name,
            'last_name' => $candidate->last_name,
            'sex' => $candidate->sex->value,
            'slogan' => $candidate->slogan,
            'biography' => $candidate->biography,
            'photo' => $candidate->photo_file === null ? null : '/media/'.ImageReEncoder::nameOf($candidate->photo_file, 480),
            'party' => $party === null ? null : [
                'name' => $party->name,
                'acronym' => $party->acronym,
                'colour' => $party->colour,
                'logo' => $party->logo_file === null ? null : '/media/'.ImageReEncoder::nameOf($party->logo_file, 192),
            ],
        ];
    }
}

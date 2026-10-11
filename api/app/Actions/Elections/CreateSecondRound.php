<?php

namespace App\Actions\Elections;

use App\Enums\ElectionStatus;
use App\Exceptions\ApiException;
use App\Models\Ballot;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Party;
use App\Models\Voter;
use App\Models\VoterGroup;
use App\Support\Audit\AuditLog;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Ramsey\Uuid\Uuid;

/**
 * A draft election for the second round of a tied ballot
 * (docs/api/results/POST-elections-{election}-second-round.md): one transaction, the first election's
 * row locked first. The new election holds that one ballot with the tied candidates and the seats the
 * tie left, the groups and the voters of the first (no code, no participation, no vote). The first
 * election is not changed. Photos and logos are not copied (their files are not shared).
 */
final class CreateSecondRound
{
    private const TITLE_LIMIT = 200;

    /** Voters written in one statement. */
    private const CHUNK = 500;

    public function __construct(private readonly ComputeResults $results) {}

    /**
     * @throws ApiException 404, 409 `results_not_available`, `second_round_exists`, `ballot_not_tied`
     * @throws ValidationException 422 `ballot: invalid`
     */
    public function __invoke(Election $election, string $ballotUuid): Election
    {
        return DB::transaction(function () use ($election, $ballotUuid): Election {
            $locked = Election::query()->whereKey($election->getKey())->lockForUpdate()->first();

            if ($locked === null) {
                throw new ApiException(404, 'not_found');
            }

            if (! in_array($locked->status, [ElectionStatus::Closed, ElectionStatus::Published, ElectionStatus::Archived], true)) {
                throw new ApiException(409, 'results_not_available');
            }

            // A ballot of this election only: unknown, another election's and another institution's are one answer.
            $source = Ballot::query()->where('election_id', $locked->id)->where('uuid', $ballotUuid)->first();

            if ($source === null) {
                throw ValidationException::withMessages(['ballot' => ['invalid']]);
            }

            $exists = Ballot::query()
                ->where('source_ballot_id', $source->id)
                ->whereIn('election_id', Election::query()->where('parent_election_id', $locked->id)->select('id'))
                ->exists();

            if ($exists) {
                throw new ApiException(409, 'second_round_exists');
            }

            $tie = $this->results->__invoke($locked, $source)[0]['tie'] ?? null;

            if ($tie === null) {
                throw new ApiException(409, 'ballot_not_tied');
            }

            $new = $this->write($locked, $source, $tie['candidates'], $tie['seats_left']);
            AuditLog::record('second_round.created', $new, ['from' => $locked->uuid, 'ballot' => $source->uuid]);

            return $new;
        });
    }

    /**
     * @param  list<string>  $tiedUuids  the tied candidates
     */
    private function write(Election $first, Ballot $source, array $tiedUuids, int $seats): Election
    {
        $prefix = $first->language === 'en' ? 'Second round – ' : 'Second tour – ';

        $new = new Election([
            'title' => mb_substr($prefix.$first->title, 0, self::TITLE_LIMIT),
            'description' => $first->description,
            'starts_at' => $first->starts_at,
            'ends_at' => $first->ends_at,
            'timezone' => $first->timezone,
            'language' => $first->language,
            'candidate_order' => $first->candidate_order,
            'results_display' => $first->results_display,
        ]);
        $new->parent_election_id = $first->id;
        $new->save();

        // The groups, then the ballot's links to them.
        /** @var array<int, int> $groupIds the first election's group key to the new one's */
        $groupIds = [];

        foreach ($first->voterGroups()->get() as $group) {
            $copy = new VoterGroup(['name' => $group->name]);
            $copy->election_id = $new->id;
            $copy->save();
            $groupIds[$group->id] = $copy->id;
        }

        $ballot = new Ballot([
            'title' => $source->title,
            'description' => $source->description,
            'seats' => $seats,
            'allow_blank' => $source->allow_blank,
        ]);
        $ballot->election_id = $new->id;
        $ballot->position = 1;
        $ballot->scope = $source->scope;
        $ballot->source_ballot_id = $source->id;
        $ballot->save();

        $links = [];

        foreach ($source->groups()->pluck('voter_groups.id') as $groupId) {
            if (is_int($groupId) && isset($groupIds[$groupId])) {
                $links[] = ['ballot_id' => $ballot->id, 'voter_group_id' => $groupIds[$groupId]];
            }
        }

        if ($links !== []) {
            DB::table('ballot_voter_group')->insert($links);
        }

        $this->copyCandidates($first, $source, $new, $ballot, $tiedUuids);
        $this->copyVoters($first, $new, $groupIds);

        return $new;
    }

    /**
     * The tied candidates only, in the first election's order, with their party as a party of the new election.
     *
     * @param  list<string>  $tiedUuids
     */
    private function copyCandidates(Election $first, Ballot $source, Election $new, Ballot $ballot, array $tiedUuids): void
    {
        /** @var array<int, int> $partyIds */
        $partyIds = [];
        $position = 0;

        $candidates = Candidate::query()
            ->where('ballot_id', $source->id)
            ->whereIn('uuid', $tiedUuids)
            ->orderBy('position')
            ->orderBy('id')
            ->get();

        foreach ($candidates as $candidate) {
            $partyId = null;

            if ($candidate->party_id !== null) {
                if (! isset($partyIds[$candidate->party_id])) {
                    $party = Party::query()->where('election_id', $first->id)->whereKey($candidate->party_id)->first();

                    if ($party !== null) {
                        $copy = new Party(['name' => $party->name, 'acronym' => $party->acronym, 'colour' => $party->colour]);
                        $copy->election_id = $new->id;
                        $copy->save();
                        $partyIds[$candidate->party_id] = $copy->id;
                    }
                }

                $partyId = $partyIds[$candidate->party_id] ?? null;
            }

            $copy = new Candidate([
                'first_name' => $candidate->first_name,
                'last_name' => $candidate->last_name,
                'sex' => $candidate->sex,
                'slogan' => $candidate->slogan,
                'biography' => $candidate->biography,
            ]);
            $copy->election_id = $new->id;
            $copy->ballot_id = $ballot->id;
            $copy->party_id = $partyId;
            $copy->position = ++$position;
            $copy->save();
        }
    }

    /**
     * Identifier, email, phone and group; nothing else is read. Written in chunks, in the first election's order.
     *
     * @param  array<int, int>  $groupIds
     */
    private function copyVoters(Election $first, Election $new, array $groupIds): void
    {
        $now = now('UTC')->format('Y-m-d H:i:s');

        Voter::query()
            ->where('election_id', $first->id)
            ->orderBy('id')
            ->select(['id', 'full_name', 'identifier', 'email', 'phone', 'voter_group_id'])
            ->chunkById(self::CHUNK, function ($voters) use ($new, $groupIds, $now): void {
                $rows = [];

                foreach ($voters as $voter) {
                    $rows[] = [
                        'uuid' => Uuid::uuid4()->toString(),
                        'institution_id' => $new->institution_id,
                        'election_id' => $new->id,
                        'voter_group_id' => $voter->voter_group_id === null ? null : ($groupIds[$voter->voter_group_id] ?? null),
                        'full_name' => $voter->full_name,
                        'identifier' => $voter->identifier,
                        'email' => $voter->email,
                        'phone' => $voter->phone,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                DB::table('voters')->insert($rows);
            });
    }
}

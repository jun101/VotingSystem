<?php

namespace App\Actions\Voting;

use App\Exceptions\ApiException;
use App\Models\Candidate;
use App\Models\Election;
use App\Models\Voter;
use App\Models\Voting\Participation;
use App\Models\Voting\Vote;
use App\Models\Voting\VoteAudit;
use App\Models\Voting\VoteChoice;
use App\Support\Voting\AuditSeal;
use App\Support\Voting\VotePhase;
use App\Support\Voting\VoterBallots;
use App\Support\Voting\VoterContexts;
use App\Support\Voting\VoterSession;
use App\Support\Voting\VotingElections;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Ramsey\Uuid\Uuid;

/**
 * Records a voter's ballots: one participation, one paper per ballot, its choices and its sealed
 * audit record, in ONE transaction (docs/design/database.md section 4.1, FR-SEC-01, FR-SEC-02).
 *
 * Order, all under the lock of the voter's row, taken first, so a second request for the same voter
 * waits here:
 *  1. the election is open: its status AND the server time inside its period (FR-ELEC-05);
 *  2. the code of the session is still the voter's (a reissue ends the sessions it replaced);
 *  3. the voter has not voted;
 *  4. the submission holds exactly the ballots the voter is eligible for (FR-SEC-07);
 *  5. each ballot's choices break no rule;
 *  6. the audit key is usable, then everything is written.
 * The unique key on `participations (election_id, voter_id)` is the last line of defence.
 *
 * The papers, their choices and their audit rows are written in a random order that follows nothing:
 * the three tables have no numeric key and no timestamp, and nothing here logs what was chosen.
 *
 * Stateless: everything comes in as arguments.
 */
final class RecordVote
{
    public function __construct(
        private readonly VoterBallots $ballots,
        private readonly VoterContexts $contexts,
        private readonly VotingElections $elections,
    ) {}

    /**
     * @param  list<array{ballot: string, candidates: list<string>}>  $submitted  the validated body
     * @return Carbon the participation's time (UTC, to the second)
     *
     * @throws ApiException 401 `voter_session_expired`, 409 `election_not_open`, 409 `already_voted`,
     *                      422 `ballots_mismatch`, 422 `choices_invalid`
     */
    public function __invoke(Election $election, VoterSession $session, array $submitted): Carbon
    {
        return DB::transaction(function () use ($election, $session, $submitted): Carbon {
            // 0. The voter's row, locked first: a second request of the same voter waits here.
            // A voter has no institution user: the scope would match nothing.
            $voter = Voter::withoutInstitutionScope()
                ->whereKey($session->voterId)
                ->where('election_id', $election->id)
                ->lockForUpdate()
                ->first();

            if ($voter === null) {
                throw $this->contexts->expired();
            }

            // 1. The election, read after the lock, with a shared lock: a close in progress is seen or waited for.
            // The election of the voter's session, for a voter without an institution user.
            $current = Election::withoutInstitutionScope()->whereKey($election->id)->sharedLock()->first();

            if ($current === null) {
                throw new ApiException(404, 'not_found');
            }

            $now = Carbon::now('UTC');
            $phase = $this->elections->phase($current, $now);

            if ($phase !== VotePhase::OPEN) {
                throw new ApiException(409, 'election_not_open', phase: $phase);
            }

            // 2. The code the session was opened with is still the voter's current one.
            if (! $this->contexts->codeIsCurrent($voter, $session)) {
                throw $this->contexts->expired();
            }

            // 3. One vote per voter.
            if (Participation::query()->where('election_id', $current->id)->where('voter_id', $voter->id)->exists()) {
                throw new ApiException(409, 'already_voted');
            }

            // 4 and 5. The ballots and the choices.
            $papers = $this->papers($current, $voter, $submitted);

            // 6. The key is checked before anything is written: no key, no vote.
            $key = AuditSeal::publicKey(Config::get('votes.audit_public_key'));

            try {
                Participation::query()->insert([
                    'election_id' => $current->id,
                    'voter_id' => $voter->id,
                    'voted_at' => $now->format('Y-m-d H:i:s'),
                ]);
            } catch (QueryException $e) {
                if (($e->errorInfo[1] ?? null) === 1062) {
                    throw new ApiException(409, 'already_voted');
                }

                throw $e;
            }

            $votes = [];
            $choices = [];
            $audit = [];

            foreach ($papers as $paper) {
                $votes[] = ['uuid' => $paper['vote'], 'election_id' => $current->id, 'ballot_id' => $paper['ballot_id']];

                foreach ($paper['candidates'] as $candidateKey) {
                    $choices[] = ['vote_uuid' => $paper['vote'], 'candidate_id' => $candidateKey];
                }

                $record = Uuid::uuid4()->toString();
                $audit[] = [
                    'uuid' => $record,
                    'election_id' => $current->id,
                    'payload' => AuditSeal::seal(AuditSeal::content($record, $current->uuid, $paper['vote'], $voter->uuid, $paper['ballot_uuid'], $now->getTimestamp()), $key),
                ];
            }

            // Each table in an order of its own, drawn at random: nothing lines the three up.
            Vote::query()->insert(self::shuffled($votes));
            if ($choices !== []) {
                VoteChoice::query()->insert(self::shuffled($choices));
            }
            VoteAudit::query()->insert(self::shuffled($audit));

            return $now;
        }, 3);
    }

    /**
     * The papers to write: one per eligible ballot, once the submission is shown to hold exactly
     * those ballots and choices that break no rule.
     *
     * @param  list<array{ballot: string, candidates: list<string>}>  $submitted
     * @return list<array{vote: string, ballot_id: int, ballot_uuid: string, candidates: list<int>}>
     */
    private function papers(Election $election, Voter $voter, array $submitted): array
    {
        $eligible = $this->ballots->eligible($election, $voter)->keyBy('uuid');

        $given = [];
        foreach ($submitted as $item) {
            $given[] = Str::lower($item['ballot']);
        }

        // Exactly the eligible ballots: none missing, none extra, none twice.
        if (count($given) !== count(array_unique($given)) || count($given) !== $eligible->count() || array_diff($given, $eligible->keys()->all()) !== []) {
            throw new ApiException(422, 'ballots_mismatch');
        }

        // The candidates of these ballots, as uuid => key, for a voter without an institution user.
        $candidates = Candidate::withoutInstitutionScope()
            ->where('election_id', $election->id)
            ->whereIn('ballot_id', $eligible->pluck('id')->all())
            ->get(['id', 'uuid', 'ballot_id']);

        $papers = [];

        foreach ($submitted as $item) {
            $ballot = $eligible->get(Str::lower($item['ballot']));

            if ($ballot === null) {
                throw new ApiException(422, 'ballots_mismatch');
            }

            /** @var array<string, int> $own */
            $own = [];
            foreach ($candidates as $candidate) {
                if ($candidate->ballot_id === $ballot->id) {
                    $own[$candidate->uuid] = $candidate->id;
                }
            }

            $chosen = array_map(Str::lower(...), $item['candidates']);

            if (count($chosen) !== count(array_unique($chosen))
                || count($chosen) > $ballot->seats
                || ($chosen === [] && ! $ballot->allow_blank)) {
                throw new ApiException(422, 'choices_invalid');
            }

            $keys = [];
            foreach ($chosen as $uuid) {
                if (! isset($own[$uuid])) {
                    throw new ApiException(422, 'choices_invalid');
                }
                $keys[] = $own[$uuid];
            }

            $papers[] = [
                'vote' => Uuid::uuid4()->toString(),
                'ballot_id' => $ballot->id,
                'ballot_uuid' => $ballot->uuid,
                'candidates' => $keys,
            ];
        }

        return $papers;
    }

    /**
     * A random order from the system's CSPRNG (Fisher-Yates).
     *
     * @template T
     *
     * @param  list<T>  $rows
     * @return list<T>
     */
    private static function shuffled(array $rows): array
    {
        for ($i = count($rows) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$rows[$i], $rows[$j]] = [$rows[$j], $rows[$i]];
        }

        return array_values($rows);
    }
}

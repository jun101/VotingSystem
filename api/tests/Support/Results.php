<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;
use Ramsey\Uuid\Uuid;

/**
 * Plants anonymous vote papers (slice 15, docs/slices/15-results.md): one `votes` row for a ballot and its
 * `vote_choices`. No voter is linked, as in the real tables. Part of the acceptance harness: it is not edited when a
 * slice is coded.
 */
final class Results
{
    /**
     * @param  list<string>  $candidates  candidate uuids ticked on the paper; none is a blank vote
     * @param  int  $times  how many identical papers
     */
    public static function paper(string $ballot, array $candidates = [], int $times = 1): void
    {
        $db = DB::connection(useMigratorConnection());
        $row = $db->table('ballots')->where('uuid', $ballot)->first();
        $ids = $candidates === [] ? [] : $db->table('candidates')->whereIn('uuid', $candidates)->pluck('id', 'uuid')->all();

        foreach (range(1, $times) as $i) {
            $uuid = Uuid::uuid4()->toString();
            $db->table('votes')->insert(['uuid' => $uuid, 'election_id' => $row->election_id, 'ballot_id' => $row->id]);
            foreach ($candidates as $candidate) {
                $db->table('vote_choices')->insert(['vote_uuid' => $uuid, 'candidate_id' => $ids[$candidate]]);
            }
        }
    }

    /** `$count` voters of the election (uuids), all marked as having voted. Returns their uuids. */
    public static function voters(string $election, int $count, ?string $group = null): array
    {
        $out = [];
        foreach (range(1, $count) as $i) {
            $voter = Accounts::plantVoter(['election' => $election, 'group' => $group]);
            Turnout::voted($voter, '2026-10-12 13:00:00');
            $out[] = $voter;
        }

        return $out;
    }
}

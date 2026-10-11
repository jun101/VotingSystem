<?php

namespace Tests\Support;

use Illuminate\Support\Facades\DB;

/**
 * Plants who has voted (slice 14, docs/slices/14-turnout-and-dashboard.md). Only the participation: turnout never reads
 * a vote. Part of the acceptance harness: it is not edited when a slice is coded.
 */
final class Turnout
{
    /** Marks a voter (uuid) as having voted at `$at` (UTC, any format Carbon reads). Returns nothing. */
    public static function voted(string $voter, string $at): void
    {
        $db = DB::connection(useMigratorConnection());
        $row = $db->table('voters')->where('uuid', $voter)->first();

        $db->table('participations')->insert([
            'election_id' => $row->election_id,
            'voter_id' => $row->id,
            'voted_at' => \Carbon\Carbon::parse($at, 'UTC')->format('Y-m-d H:i:s'),
        ]);
    }
}

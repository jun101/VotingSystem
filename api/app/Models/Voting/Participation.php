<?php

namespace App\Models\Voting;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Carbon;

/**
 * "This voter has voted in this election" (docs/design/database.md section 2.4). No key of its
 * own, no uuid, no `updated_at`: written once, never changed, never named by an address.
 *
 * It sits in `app/Models/Voting`, not in `app/Models`: the tenant-coverage test reads the
 * models of `app/Models` for an `institution_id` column, and this table has none by design
 * (the election and the voter already name the institution).
 *
 * @property int $election_id
 * @property int $voter_id
 * @property Carbon $voted_at
 */
class Participation extends Model
{
    protected $table = 'participations';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $guarded = ['*'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['voted_at' => 'datetime'];
    }
}

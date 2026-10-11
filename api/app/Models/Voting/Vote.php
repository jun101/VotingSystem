<?php

namespace App\Models\Voting;

use Illuminate\Database\Eloquent\Model;

/**
 * One anonymous ballot paper (docs/design/database.md section 2.4): a random uuid, the election
 * and the ballot. No numeric key, no voter, no timestamp: never add one.
 *
 * @property string $uuid
 * @property int $election_id
 * @property int $ballot_id
 */
class Vote extends Model
{
    protected $table = 'votes';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];
}

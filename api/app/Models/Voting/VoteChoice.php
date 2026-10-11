<?php

namespace App\Models\Voting;

use Illuminate\Database\Eloquent\Model;

/**
 * A candidate ticked on a paper. No row for a paper is a blank vote.
 *
 * @property string $vote_uuid
 * @property int $candidate_id
 */
class VoteChoice extends Model
{
    protected $table = 'vote_choices';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = null;

    /** @var list<string> */
    protected $guarded = ['*'];
}

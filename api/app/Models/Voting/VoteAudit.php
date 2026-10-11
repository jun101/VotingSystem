<?php

namespace App\Models\Voting;

use Illuminate\Database\Eloquent\Model;

/**
 * The sealed link between a vote and its voter. Written, never read by the application: only
 * the operator's tool, with the private key, opens it (FR-SEC-08, FR-SEC-09).
 *
 * @property string $uuid
 * @property int $election_id
 * @property string $payload
 */
class VoteAudit extends Model
{
    protected $table = 'vote_audit';

    public $timestamps = false;

    public $incrementing = false;

    protected $primaryKey = 'uuid';

    protected $keyType = 'string';

    /** @var list<string> */
    protected $guarded = ['*'];
}

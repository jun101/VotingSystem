<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use App\Models\Voting\Participation;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Support\Carbon;

/**
 * A voter of an election (docs/design/database.md section 2.2).
 *
 * @property int $id
 * @property int $election_id
 * @property int|null $voter_group_id
 * @property string $uuid
 * @property string $full_name
 * @property string|null $identifier
 * @property string|null $email
 * @property string|null $phone
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Voter extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    /** An election holds at most this many voters. */
    public const LIMIT = 10000;

    // The institution, the election and the group are never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['full_name', 'identifier', 'email', 'phone'];

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /** @return BelongsTo<VoterGroup, $this> */
    public function group(): BelongsTo
    {
        return $this->belongsTo(VoterGroup::class, 'voter_group_id');
    }

    /**
     * "This voter has voted" (slice 14): the participation, never the vote. Read for the flag and the
     * time only; a ballot is not reachable from here.
     *
     * @return HasOne<Participation, $this>
     */
    public function participation(): HasOne
    {
        return $this->hasOne(Participation::class, 'voter_id');
    }

    /**
     * The voter's current access code, as a hash (none until one is issued).
     *
     * @return HasOne<Credential, $this>
     */
    public function credential(): HasOne
    {
        return $this->hasOne(Credential::class);
    }
}

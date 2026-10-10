<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
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
}

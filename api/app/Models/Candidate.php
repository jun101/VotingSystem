<?php

namespace App\Models;

use App\Enums\Sex;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A person standing on a ballot (docs/design/database.md section 2.2).
 *
 * @property int $election_id
 * @property int $ballot_id
 * @property int|null $party_id
 * @property string $uuid
 * @property string $first_name
 * @property string $last_name
 * @property Sex $sex
 * @property string|null $slogan
 * @property string|null $biography
 * @property string|null $photo_file
 * @property int $position
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Candidate extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // The institution, the election, the ballot, the party, the photo and the position are never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['first_name', 'last_name', 'sex', 'slogan', 'biography'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'sex' => Sex::class,
        ];
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /** @return BelongsTo<Ballot, $this> */
    public function ballot(): BelongsTo
    {
        return $this->belongsTo(Ballot::class);
    }

    /** @return BelongsTo<Party, $this> */
    public function party(): BelongsTo
    {
        return $this->belongsTo(Party::class);
    }
}

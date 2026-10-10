<?php

namespace App\Models;

use App\Enums\BallotScope;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A position to fill in an election (docs/design/database.md section 2.2).
 *
 * @property int $election_id
 * @property string $uuid
 * @property string $title
 * @property string|null $description
 * @property int $position
 * @property int $seats
 * @property bool $allow_blank
 * @property BallotScope $scope
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Ballot extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // The institution, the election, the position and the scope are never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['title', 'description', 'seats', 'allow_blank'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'seats' => 1,
        'allow_blank' => true,
        'scope' => 'general',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'position' => 'integer',
            'seats' => 'integer',
            'allow_blank' => 'boolean',
            'scope' => BallotScope::class,
        ];
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /**
     * The candidates of the ballot, in display order.
     *
     * @return HasMany<Candidate, $this>
     */
    public function candidates(): HasMany
    {
        return $this->hasMany(Candidate::class)->orderBy('position')->orderBy('id')->chaperone('ballot');
    }
}

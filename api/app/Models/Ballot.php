<?php

namespace App\Models;

use App\Enums\BallotScope;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
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

    /**
     * The groups a group ballot covers (FR-BAL-03), Empty for a general ballot.
     *
     * @return BelongsToMany<VoterGroup, $this>
     */
    public function groups(): BelongsToMany
    {
        return $this->belongsToMany(VoterGroup::class, 'ballot_voter_group');
    }

    /**
     * What the resource needs about the scope, in two queries for a whole list: the groups with their
     * voter counts, and the number of voters of the election (for the general ballots).
     *
     * @param  Builder<Ballot>  $query
     * @return Builder<Ballot>
     */
    public function scopeWithScopeData(Builder $query): Builder
    {
        if ($query->getQuery()->columns === null) {
            $query->select('ballots.*');
        }

        return $query
            ->addSelect(['election_voters_count' => Voter::query()
                ->selectRaw('count(*)')
                ->whereColumn('voters.election_id', 'ballots.election_id'), ])
            ->with(['groups' => fn ($groups) => $groups->withCount('voters')]);
    }
}

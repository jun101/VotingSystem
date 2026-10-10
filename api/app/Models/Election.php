<?php

namespace App\Models;

use App\Enums\CandidateOrder;
use App\Enums\ElectionStatus;
use App\Enums\ResultsDisplay;
use App\Exceptions\ApiException;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An election of an institution (docs/design/database.md section 2.2). Dates are UTC; the
 * `timezone` says how to show them.
 *
 * @property int $id
 * @property string $uuid
 * @property string $title
 * @property string|null $description
 * @property Carbon $starts_at
 * @property Carbon $ends_at
 * @property string $timezone
 * @property string $language
 * @property ElectionStatus $status
 * @property string|null $cover_file
 * @property CandidateOrder $candidate_order
 * @property ResultsDisplay $results_display
 * @property Carbon|null $created_at
 */
class Election extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // The institution, the status, the cover and the dates of its life are never mass-assigned:
    // a request cannot reach them.
    /** @var list<string> */
    protected $fillable = ['title', 'description', 'starts_at', 'ends_at', 'timezone', 'language', 'candidate_order', 'results_display'];

    /** @var array<string, mixed> */
    protected $attributes = [
        'status' => 'draft',
        'candidate_order' => 'manual',
        'results_display' => 'full',
    ];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ElectionStatus::class,
            'candidate_order' => CandidateOrder::class,
            'results_display' => ResultsDisplay::class,
            'starts_at' => 'datetime',
            'ends_at' => 'datetime',
            'opened_at' => 'datetime',
            'closed_at' => 'datetime',
            'published_at' => 'datetime',
            'archived_at' => 'datetime',
        ];
    }

    /**
     * Whether the settings and the cover may change, and the election be deleted. The one place
     * that decides it: slices 09 and 13 widen it here.
     */
    public function isEditable(): bool
    {
        return $this->status === ElectionStatus::Draft;
    }

    /**
     * @param  string  $messageKey  the message to give: the change of a draft, or its deletion
     *
     * @throws ApiException 409 `election_not_editable`
     */
    public function assertEditable(string $messageKey = 'election_not_editable'): void
    {
        if (! $this->isEditable()) {
            throw new ApiException(409, 'election_not_editable', messageKey: $messageKey);
        }
    }

    /**
     * The positions of the election, in display order.
     *
     * @return HasMany<Ballot, $this>
     */
    public function ballots(): HasMany
    {
        return $this->hasMany(Ballot::class)->orderBy('position')->orderBy('id');
    }

    /**
     * The parties of the election, by name ignoring case, then creation order.
     *
     * @return HasMany<Party, $this>
     */
    public function parties(): HasMany
    {
        return $this->hasMany(Party::class)->orderBy('name_key')->orderBy('id');
    }
}

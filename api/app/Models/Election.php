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
     * Whether voters may be added and edited: a draft, a scheduled or an open election. The
     * one place that decides it: slice 12 knows who has voted.
     */
    public function isVotersEditable(): bool
    {
        return in_array($this->status, [ElectionStatus::Draft, ElectionStatus::Scheduled, ElectionStatus::Open], true);
    }

    /** Whether voters may be deleted, and groups added, renamed, merged and deleted: a draft or a scheduled election. */
    public function isVotersDeletable(): bool
    {
        return in_array($this->status, [ElectionStatus::Draft, ElectionStatus::Scheduled], true);
    }

    /** @throws ApiException 409 `election_voters_locked` */
    public function assertVotersEditable(): void
    {
        if (! $this->isVotersEditable()) {
            throw new ApiException(409, 'election_voters_locked');
        }
    }

    /** @throws ApiException 409 `election_voters_locked` */
    public function assertVotersDeletable(): void
    {
        if (! $this->isVotersDeletable()) {
            throw new ApiException(409, 'election_voters_locked');
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

    /**
     * The groups of voters of the election, by name ignoring case, then creation order.
     *
     * @return HasMany<VoterGroup, $this>
     */
    public function voterGroups(): HasMany
    {
        return $this->hasMany(VoterGroup::class)->orderBy('name_key')->orderBy('id');
    }

    /**
     * The voters of the election, by full name ignoring case, then creation order.
     *
     * @return HasMany<Voter, $this>
     */
    public function voters(): HasMany
    {
        return $this->hasMany(Voter::class)->orderBy('full_name')->orderBy('id');
    }
}

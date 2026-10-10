<?php

namespace App\Models;

use App\Enums\VoterImportStatus;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A voter file uploaded to an election (docs/design/database.md section 2.3). The file is on the
 * private `imports` disk under `stored_file`; `original_name` is for display only.
 *
 * @property int $id
 * @property string $uuid
 * @property int $election_id
 * @property int|null $user_id
 * @property string $original_name
 * @property string $stored_file
 * @property list<string>|null $columns
 * @property array<string, int|null>|null $mapping
 * @property VoterImportStatus $status
 * @property string|null $failure
 * @property int $total_rows
 * @property int $valid_rows
 * @property int $invalid_rows
 * @property int $duplicate_rows
 * @property list<string>|null $new_groups
 * @property int $processed_rows
 * @property int $imported_rows
 * @property Carbon|null $finished_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VoterImport extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    /** An election takes at most this many data rows from one file. */
    public const MAX_ROWS = 10000;

    /** The voter fields a column can feed, in the order of the template. */
    public const FIELDS = ['full_name', 'group', 'identifier', 'email', 'phone'];

    // Nothing is mass-assigned: the importer sets each attribute itself.
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => VoterImportStatus::class,
            'columns' => 'array',
            'mapping' => 'array',
            'new_groups' => 'array',
            'finished_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /** @return HasMany<VoterImportRow, $this> */
    public function rows(): HasMany
    {
        return $this->hasMany(VoterImportRow::class)->orderBy('line');
    }

    /** Whether the error file has something in it: rejected rows of a checked or finished import. */
    public function hasErrorFile(): bool
    {
        return $this->invalid_rows > 0
            && in_array($this->status, [VoterImportStatus::Checked, VoterImportStatus::Done], true);
    }
}

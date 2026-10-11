<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A file of slips written by a run (docs/design/database.md section 2.3). The bytes are on the
 * private `credentials` disk under `stored_file`, encrypted; the name is never output.
 *
 * @property int $id
 * @property string $uuid
 * @property string|null $run
 * @property int $election_id
 * @property int|null $user_id
 * @property string $kind
 * @property int|null $voter_group_id
 * @property int $part
 * @property int $parts
 * @property int $voter_count
 * @property string $status
 * @property string|null $failure
 * @property bool $send_email
 * @property bool $replace
 * @property bool $ungrouped
 * @property string|null $stored_file
 * @property int|null $size_bytes
 * @property Carbon $expires_at
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class GeneratedFile extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    public const KIND_SLIPS = 'slips_pdf';

    public const KIND_CODES_XLSX = 'codes_xlsx';

    public const KIND_CODES_CSV = 'codes_csv';

    /** The kinds of the self-distribution file of a run. */
    public const CODE_KINDS = [self::KIND_CODES_XLSX, self::KIND_CODES_CSV];

    public const STATUS_QUEUED = 'queued';

    public const STATUS_READY = 'ready';

    public const STATUS_FAILED = 'failed';

    public const FAILURE_GENERATION = 'generation_failed';

    // Nothing is mass-assigned: the run sets each attribute itself.
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'expires_at' => 'datetime',
            'send_email' => 'boolean',
            'replace' => 'boolean',
            'ungrouped' => 'boolean',
        ];
    }

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

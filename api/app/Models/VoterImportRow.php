<?php

namespace App\Models;

use App\Enums\ImportRowStatus;
use App\Models\Concerns\BelongsToInstitution;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One checked row of a voter file (docs/design/database.md section 2.3). Internal: it has no UUID
 * and is never shown as a record of its own, only as a line of the preview and of the error file.
 *
 * @property int $id
 * @property int $voter_import_id
 * @property int $line
 * @property ImportRowStatus $status
 * @property list<string> $reasons
 * @property string|null $full_name
 * @property string|null $group_name
 * @property string|null $identifier
 * @property string|null $email
 * @property string|null $phone
 * @property array<string, string|null>|null $raw
 */
class VoterImportRow extends Model
{
    use BelongsToInstitution;

    public $timestamps = false;

    // Written by the check job only.
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'status' => ImportRowStatus::class,
            'reasons' => 'array',
            'raw' => 'array',
        ];
    }

    /** @return BelongsTo<VoterImport, $this> */
    public function voterImport(): BelongsTo
    {
        return $this->belongsTo(VoterImport::class);
    }
}

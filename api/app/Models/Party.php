<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * A slate or list of an election (docs/design/database.md section 2.2).
 *
 * @property int $election_id
 * @property string $uuid
 * @property string $name
 * @property string $name_key
 * @property string|null $acronym
 * @property string $colour
 * @property string|null $logo_file
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class Party extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // The institution, the election and the logo are never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['name', 'acronym', 'colour'];

    /** Setting the name sets its comparison key: trimmed and lower-cased. */
    public function setNameAttribute(string $value): void
    {
        $name = trim($value);

        $this->attributes['name'] = $name;
        $this->attributes['name_key'] = mb_strtolower($name);
    }

    public function setColourAttribute(string $value): void
    {
        $this->attributes['colour'] = strtoupper($value);
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }
}

<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * A group of voters of an election (docs/design/database.md section 2.2).
 *
 * @property int $id
 * @property int $election_id
 * @property string $uuid
 * @property string $name
 * @property string $name_key
 * @property Carbon|null $created_at
 * @property Carbon|null $updated_at
 */
class VoterGroup extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // The institution and the election are never mass-assigned.
    /** @var list<string> */
    protected $fillable = ['name'];

    /** The comparison key of a name: trimmed and lower-cased (accents are kept). */
    public static function keyOf(string $name): string
    {
        return mb_strtolower(trim($name));
    }

    /** Setting the name sets its comparison key. */
    public function setNameAttribute(string $value): void
    {
        $name = trim($value);

        $this->attributes['name'] = $name;
        $this->attributes['name_key'] = self::keyOf($name);
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /** @return HasMany<Voter, $this> */
    public function voters(): HasMany
    {
        return $this->hasMany(Voter::class);
    }
}

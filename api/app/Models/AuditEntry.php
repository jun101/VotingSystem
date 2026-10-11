<?php

namespace App\Models;

use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * One line of the audit log (docs/design/database.md section 2.5). Written once by `AuditLog::record`, never
 * changed: the application's database account has no UPDATE and no DELETE on the table.
 *
 * `institution_id` is null for an action of the platform; such an entry is never found through the tenant
 * scope. No `updated_at`.
 *
 * @property int $id
 * @property string $uuid
 * @property int|null $institution_id
 * @property int|null $user_id
 * @property string $actor_name
 * @property string $action
 * @property int|null $election_id
 * @property string|null $subject_type
 * @property string|null $subject_uuid
 * @property array<string, mixed>|null $details
 * @property string|null $ip_address
 * @property Carbon $created_at
 */
class AuditEntry extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    public $timestamps = false;

    // Nothing is mass-assigned: `AuditLog` sets each attribute itself.
    /** @var list<string> */
    protected $fillable = [];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return ['details' => 'array', 'created_at' => 'datetime'];
    }

    /** A platform action has no institution (the sign-in of a platform admin). */
    public function allowsNoInstitution(): bool
    {
        return true;
    }

    /** @return BelongsTo<Election, $this> */
    public function election(): BelongsTo
    {
        return $this->belongsTo(Election::class);
    }

    /**
     * The actor, even when removed since (a removed user keeps their name in the log).
     *
     * @return BelongsTo<User, $this>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class)->withTrashed();
    }
}

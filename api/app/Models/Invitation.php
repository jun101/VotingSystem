<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\BelongsToInstitution;
use App\Models\Concerns\HasUuid;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

/**
 * An invitation to join an institution (FR-INST-03). Only the SHA-256 hash of the token is
 * stored; the token itself exists in the email and nowhere else.
 *
 * @property string $uuid
 * @property string $email
 * @property Role $role
 * @property string $token_hash
 * @property int $invited_by_user_id
 * @property Carbon $expires_at
 * @property Carbon|null $accepted_at
 * @property Carbon $created_at
 * @property User|null $inviter
 */
class Invitation extends Model
{
    use BelongsToInstitution;
    use HasUuid;

    // No `updated_at` column: an invitation is written once, then accepted or deleted.
    public const UPDATED_AT = null;

    // Nothing is mass-assigned: the code that creates an invitation sets each column.
    /** @var list<string> */
    protected $fillable = [];

    /** @var list<string> */
    protected $hidden = ['token_hash'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'institution_id' => 'integer',
            'invited_by_user_id' => 'integer',
            'expires_at' => 'datetime',
            'accepted_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    /**
     * Whoever sent it; their name is kept even after they are removed.
     *
     * @return BelongsTo<User, $this>
     */
    public function inviter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'invited_by_user_id')->withTrashed();
    }

    public function isExpired(): bool
    {
        return $this->expires_at->isPast();
    }
}

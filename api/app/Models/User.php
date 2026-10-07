<?php

namespace App\Models;

use App\Enums\Role;
use App\Models\Concerns\HasUuid;
use Database\Factories\UserFactory;
use Illuminate\Contracts\Translation\HasLocalePreference;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Carbon;

/**
 * An institution user or a platform admin. A removed user keeps their row (`deleted_at`),
 * so the audit log can still name them; the soft-delete scope makes them "nobody" everywhere.
 *
 * @property string $uuid
 * @property Role $role
 * @property string $name
 * @property string $email
 * @property string $password
 * @property string $language
 * @property Carbon|null $email_verified_at
 * @property Carbon|null $last_login_at
 * @property int|null $institution_id
 * @property Institution|null $institution
 */
class User extends Authenticatable implements HasLocalePreference
{
    /** @use HasFactory<UserFactory> */
    use HasFactory;

    use HasUuid;
    use Notifiable;
    use SoftDeletes;

    /** @var list<string> */
    protected $fillable = ['institution_id', 'role', 'name', 'email', 'password', 'language'];

    /** @var list<string> */
    protected $hidden = ['password', 'two_factor_secret', 'two_factor_recovery_codes', 'two_factor_confirmed_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'role' => Role::class,
            'email_verified_at' => 'datetime',
            'last_login_at' => 'datetime',
            'two_factor_secret' => 'encrypted',
            'two_factor_recovery_codes' => 'encrypted',
            'two_factor_confirmed_at' => 'datetime',
        ];
    }

    /** @return BelongsTo<Institution, $this> */
    public function institution(): BelongsTo
    {
        return $this->belongsTo(Institution::class);
    }

    public function hasVerifiedEmail(): bool
    {
        return $this->email_verified_at !== null;
    }

    /** There is no "remember me": no remember token, no column for it. */
    public function getRememberTokenName(): string
    {
        return '';
    }

    public function preferredLocale(): string
    {
        return $this->language;
    }
}

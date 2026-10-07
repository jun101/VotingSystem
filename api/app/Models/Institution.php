<?php

namespace App\Models;

use App\Enums\InstitutionType;
use App\Models\Concerns\HasUuid;
use Database\Factories\InstitutionFactory;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Carbon;

/**
 * An institution: the tenant. No tenant scope yet; slice 03 adds it with its test suite.
 *
 * @property string $uuid
 * @property string $name
 * @property InstitutionType $type
 * @property string $timezone
 * @property string $language
 * @property Carbon|null $suspended_at
 */
class Institution extends Model
{
    /** @use HasFactory<InstitutionFactory> */
    use HasFactory;

    use HasUuid;

    /** @var list<string> */
    protected $fillable = ['name', 'type', 'timezone', 'language', 'suspended_at'];

    /** @return array<string, string> */
    protected function casts(): array
    {
        return [
            'type' => InstitutionType::class,
            'suspended_at' => 'datetime',
        ];
    }

    /** @return HasMany<User, $this> */
    public function users(): HasMany
    {
        return $this->hasMany(User::class);
    }

    public function isSuspended(): bool
    {
        return $this->suspended_at !== null;
    }
}

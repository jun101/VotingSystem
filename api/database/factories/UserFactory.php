<?php

namespace Database\Factories;

use App\Enums\Role;
use App\Models\Institution;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * A verified owner of a new institution, by default.
 *
 * @extends Factory<User>
 */
class UserFactory extends Factory
{
    protected $model = User::class;

    public const PASSWORD = 'correct horse battery staple';

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'institution_id' => Institution::factory(),
            'role' => Role::Owner,
            'name' => 'Marie Joseph',
            'email' => 'user'.Str::lower(Str::random(10)).'@example.test',
            'password' => Hash::make(self::PASSWORD),
            'email_verified_at' => now(),
            'language' => 'fr',
        ];
    }

    public function unverified(): static
    {
        return $this->state(['email_verified_at' => null]);
    }

    public function manager(): static
    {
        return $this->state(['role' => Role::Manager]);
    }

    public function platformAdmin(): static
    {
        return $this->state(['role' => Role::PlatformAdmin, 'institution_id' => null]);
    }
}

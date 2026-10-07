<?php

namespace Database\Factories;

use App\Enums\InstitutionType;
use App\Models\Institution;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Str;

/** @extends Factory<Institution> */
class InstitutionFactory extends Factory
{
    protected $model = Institution::class;

    /** @return array<string, mixed> */
    public function definition(): array
    {
        return [
            'name' => 'Collège '.Str::random(8),
            'type' => InstitutionType::Other,
            'timezone' => 'America/Port-au-Prince',
            'language' => 'fr',
        ];
    }

    public function suspended(): static
    {
        return $this->state(['suspended_at' => now()]);
    }
}

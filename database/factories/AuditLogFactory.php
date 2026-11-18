<?php

namespace Database\Factories;

use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

class AuditLogFactory extends Factory
{
    public function definition(): array
    {
        return [
            'user_id' => null,
            'action' => 'league.updated',
            'auditable_type' => League::class,
            'auditable_id' => fake()->numberBetween(1, 1000),
            'championship_id' => null,
            'league_id' => null,
            'old_values' => ['name' => fake()->words(2, true)],
            'new_values' => ['name' => fake()->words(2, true)],
            'meta' => null,
            'ip' => fake()->ipv4(),
            'user_agent' => fake()->userAgent(),
            'request_id' => fake()->uuid(),
        ];
    }
}

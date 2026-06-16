<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Coach>
 */
class CoachFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id'    => null,
            'name'       => fake()->name(),
            'birth_date' => fake()->date(),
        ];
    }
}

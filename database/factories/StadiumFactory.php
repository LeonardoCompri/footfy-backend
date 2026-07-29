<?php

namespace Database\Factories;

use App\Models\League;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stadium>
 */
class StadiumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'name' => fake()->company().' Arena',
            'city' => fake()->city(),
            'capacity' => fake()->numberBetween(5000, 80000),
        ];
    }
}

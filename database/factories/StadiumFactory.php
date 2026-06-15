<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Stadium>
 */
class StadiumFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->company().' Arena',
            'city' => fake()->city(),
            'capacity' => fake()->numberBetween(5000, 80000),
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Player>
 */
class PlayerFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id' => null,
            'name' => fake()->name(),
            'position' => fake()->randomElement(['GOL', 'ZAG', 'LAT', 'MEI', 'ATA']),
            'number' => fake()->numberBetween(1, 99),
            'birth_date' => fake()->date(),
            'document' => fake()->unique()->numerify('###########'),
        ];
    }
}

<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

class ChampionshipFactory extends Factory
{
    public function definition(): array
    {
        return [
            'name' => fake()->sentence(3),
        ];
    }
}

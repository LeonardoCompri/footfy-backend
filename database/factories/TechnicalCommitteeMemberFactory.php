<?php

namespace Database\Factories;

use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\TechnicalCommitteeMember>
 */
class TechnicalCommitteeMemberFactory extends Factory
{
    public function definition(): array
    {
        return [
            'team_id'    => null,
            'name'       => fake()->name(),
            'role'       => 'Treinador principal',
            'birth_date' => fake()->date(),
        ];
    }
}

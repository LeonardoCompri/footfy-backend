<?php

namespace Database\Factories;

use App\Models\Championship;
use App\Models\Stadium;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Game>
 */
class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'championship_id' => Championship::factory(),
            'home_team_id'    => Team::factory(),
            'away_team_id'    => Team::factory(),
            'stadium_id'      => null,
            'match_date'      => fake()->dateTime(),
            'home_score'      => null,
            'away_score'      => null,
            'round'           => fake()->numberBetween(1, 38),
            'status'          => 'scheduled',
        ];
    }
}

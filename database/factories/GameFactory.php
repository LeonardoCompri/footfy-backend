<?php

namespace Database\Factories;

use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameFactory extends Factory
{
    public function definition(): array
    {
        return [
            'league_id' => League::factory(),
            'home_team_id' => Team::factory(),
            'away_team_id' => Team::factory(),
            'stadium_id' => null,
            'match_date' => fake()->dateTime(),
            'home_score' => null,
            'away_score' => null,
            'round' => fake()->numberBetween(1, 38),
            'status' => 'scheduled',
        ];
    }
}

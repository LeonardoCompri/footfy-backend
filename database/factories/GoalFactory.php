<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends \Illuminate\Database\Eloquent\Factories\Factory<\App\Models\Goal>
 */
class GoalFactory extends Factory
{
    public function definition(): array
    {
        $homeTeam = Team::factory()->create();
        $awayTeam = Team::factory()->create();
        $game = Game::factory()->create([
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
        ]);
        $player = Player::factory()->create(['team_id' => $homeTeam->id]);

        return [
            'game_id'   => $game->id,
            'player_id' => $player->id,
            'team_id'   => $homeTeam->id,
            'minute'    => fake()->numberBetween(1, 90),
        ];
    }
}

<?php

namespace Database\Factories;

use App\Models\Game;
use App\Models\League;
use App\Models\Player;
use App\Models\Team;
use Illuminate\Database\Eloquent\Factories\Factory;

class GameEventFactory extends Factory
{
    public function definition(): array
    {
        $league = League::factory()->create();
        $homeTeam = Team::factory()->create();
        $awayTeam = Team::factory()->create();
        $game = Game::factory()->create([
            'league_id' => $league->id,
            'home_team_id' => $homeTeam->id,
            'away_team_id' => $awayTeam->id,
        ]);
        $player = Player::factory()->create(['team_id' => $homeTeam->id]);

        return [
            'game_id' => $game->id,
            'team_id' => $homeTeam->id,
            'player_id' => $player->id,
            'related_player_id' => null,
            'league_id' => $league->id,
            'type' => 'goal',
            'minute' => fake()->numberBetween(1, 90),
        ];
    }

    public function ownGoal(): static
    {
        return $this->state(fn () => ['type' => 'own_goal']);
    }

    public function yellowCard(): static
    {
        return $this->state(fn () => ['type' => 'yellow_card']);
    }

    public function redCard(): static
    {
        return $this->state(fn () => ['type' => 'red_card']);
    }
}

<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class GameEventSeeder extends Seeder
{
    public function run(): void
    {
        $playersByTeam = Player::select('id', 'team_id', 'position')->get()->groupBy('team_id');
        $now = now();
        $rows = [];

        Game::where('status', 'finished')->each(function (Game $game) use ($playersByTeam, $now, &$rows) {
            $rows = array_merge(
                $rows,
                $this->goalsFor($game, $game->home_team_id, $game->home_score, $playersByTeam, $now),
                $this->goalsFor($game, $game->away_team_id, $game->away_score, $playersByTeam, $now),
                $this->cardsFor($game, $game->home_team_id, $playersByTeam, $now),
                $this->cardsFor($game, $game->away_team_id, $playersByTeam, $now),
            );
        });

        foreach (array_chunk($rows, 200) as $chunk) {
            GameEvent::insert($chunk);
        }
    }

    private function goalsFor(Game $game, int $teamId, ?int $goals, Collection $playersByTeam, Carbon $now): array
    {
        if (! $goals) {
            return [];
        }

        $pool = $this->attackingPool($playersByTeam->get($teamId, collect()));
        if ($pool->isEmpty()) {
            return [];
        }

        $events = [];

        for ($i = 0; $i < $goals; $i++) {
            $scorer = $pool->random();
            $assist = null;

            if ($pool->count() > 1 && fake()->boolean(40)) {
                $assist = $pool->reject(fn ($player) => $player->id === $scorer->id)->random();
            }

            $events[] = [
                'game_id' => $game->id,
                'team_id' => $teamId,
                'player_id' => $scorer->id,
                'related_player_id' => $assist?->id,
                'league_id' => $game->league_id,
                'type' => 'goal',
                'minute' => fake()->numberBetween(1, 90),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        return $events;
    }

    private function cardsFor(Game $game, int $teamId, Collection $playersByTeam, Carbon $now): array
    {
        $pool = $playersByTeam->get($teamId, collect());
        if ($pool->isEmpty()) {
            return [];
        }

        $events = [];
        $yellowCards = fake()->randomElement([0, 1, 1, 2, 2, 3]);

        for ($i = 0; $i < $yellowCards; $i++) {
            $events[] = $this->cardEvent($game, $teamId, $pool->random(), 'yellow_card', $now);
        }

        if (fake()->boolean(10)) {
            $events[] = $this->cardEvent($game, $teamId, $pool->random(), 'red_card', $now);
        }

        return $events;
    }

    private function cardEvent(Game $game, int $teamId, Player $player, string $type, Carbon $now): array
    {
        return [
            'game_id' => $game->id,
            'team_id' => $teamId,
            'player_id' => $player->id,
            'related_player_id' => null,
            'league_id' => $game->league_id,
            'type' => $type,
            'minute' => fake()->numberBetween(1, 90),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function attackingPool(Collection $players): Collection
    {
        $attackers = $players->whereIn('position', ['ATA', 'MEI']);

        return $attackers->isNotEmpty() ? $attackers : $players;
    }
}

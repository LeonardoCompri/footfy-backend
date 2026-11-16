<?php

namespace Database\Seeders;

use App\Models\Championship;
use App\Models\Game;
use App\Models\GameEvent;
use App\Models\League;
use App\Models\Player;
use App\Models\Stadium;
use App\Models\Team;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class LoadTestSeeder extends Seeder
{
    private const TEAMS = 64;

    private const SCHEDULED_ROUNDS = 10;

    private const CHUNK_SIZE = 1000;

    private const SQUAD_POSITIONS = [
        'GOL' => 4,
        'ZAG' => 7,
        'LAT' => 6,
        'MEI' => 10,
        'ATA' => 8,
    ];

    public function run(): void
    {
        if (! app()->isLocal()) {
            $this->command?->error('O LoadTestSeeder só roda no ambiente local.');

            return;
        }

        DB::transaction(function () {
            $now = now();
            $firstTurn = $this->firstTurnRounds(range(1, self::TEAMS));
            $totalRounds = count($firstTurn) * 2;
            $kickoff = $now->copy()->startOfDay()->setTime(16, 0)->subDays($totalRounds - self::SCHEDULED_ROUNDS);

            $league = $this->createLeague($kickoff, $totalRounds);
            $teamIds = $this->createTeams($now);
            $league->teams()->attach(array_fill_keys($teamIds, ['group_number' => 1]));

            $stadiumIdByTeam = $this->createStadiums($league, $teamIds, $now);
            $playersByTeam = $this->createPlayers($teamIds, $now);

            $this->createGames($league, $teamIds, $firstTurn, $stadiumIdByTeam, $kickoff, $now);
            $this->createEvents($league, $playersByTeam, $now);
        });
    }

    private function createLeague(Carbon $kickoff, int $totalRounds): League
    {
        $championship = Championship::create(['name' => 'Campeonato de Carga']);

        return League::create([
            'name' => 'Liga de Carga '.$kickoff->format('Y'),
            'championship_id' => $championship->id,
            'start_date' => $kickoff->toDateString(),
            'end_date' => $kickoff->copy()->addDays($totalRounds - 1)->toDateString(),
            'organizer' => 'Footfy',
            'season' => $kickoff->format('Y'),
            'format' => 'hybrid',
            'fixture_mode' => 'draw',
            'groups_count' => 1,
            'group_legs' => 2,
            'qualifiers_per_group' => 8,
            'knockout_size' => 8,
            'knockout_legs' => 2,
            'final_legs' => 1,
            'third_place' => false,
            'knockout_tiebreakers' => ['penalties'],
        ]);
    }

    private function createTeams(Carbon $now): array
    {
        $rows = [];

        for ($i = 1; $i <= self::TEAMS; $i++) {
            $rows[] = [
                'name' => sprintf('Time de Carga %02d', $i),
                'nickname' => sprintf('Carga %02d', $i),
                'created_at' => $now,
                'updated_at' => $now,
            ];
        }

        $firstId = (int) Team::max('id');
        Team::insert($rows);

        return Team::where('id', '>', $firstId)->orderBy('id')->pluck('id')->all();
    }

    private function createStadiums(League $league, array $teamIds, Carbon $now): array
    {
        $rows = array_map(fn (int $teamId, int $index) => [
            'league_id' => $league->id,
            'name' => sprintf('Estádio de Carga %02d', $index + 1),
            'city' => fake()->city(),
            'capacity' => fake()->numberBetween(5000, 80000),
            'created_at' => $now,
            'updated_at' => $now,
        ], $teamIds, array_keys($teamIds));

        Stadium::insert($rows);

        $stadiumIds = Stadium::where('league_id', $league->id)->orderBy('id')->pluck('id')->all();

        return array_combine($teamIds, $stadiumIds);
    }

    private function createPlayers(array $teamIds, Carbon $now): Collection
    {
        $rows = [];

        foreach ($teamIds as $teamId) {
            $number = 0;

            foreach (self::SQUAD_POSITIONS as $position => $count) {
                for ($i = 0; $i < $count; $i++) {
                    $rows[] = [
                        'team_id' => $teamId,
                        'name' => fake()->name(),
                        'position' => $position,
                        'number' => ++$number,
                        'birth_date' => fake()->dateTimeBetween('-36 years', '-18 years')->format('Y-m-d'),
                        'document' => fake()->unique()->numerify('###########'),
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            Player::insert($chunk);
        }

        return Player::whereIn('team_id', $teamIds)
            ->get(['id', 'team_id', 'position'])
            ->groupBy('team_id');
    }

    private function createGames(League $league, array $teamIds, array $firstTurn, array $stadiumIdByTeam, Carbon $kickoff, Carbon $now): void
    {
        $turnRounds = count($firstTurn);
        $finishedRounds = $turnRounds * 2 - self::SCHEDULED_ROUNDS;
        $rows = [];

        foreach ([1, 2] as $turn) {
            foreach ($firstTurn as $index => $matches) {
                $round = ($turn - 1) * $turnRounds + $index + 1;
                $finished = $round <= $finishedRounds;
                $matchDate = $kickoff->copy()->addDays($round - 1);

                foreach ($matches as [$home, $away]) {
                    [$homeId, $awayId] = $turn === 1
                        ? [$teamIds[$home - 1], $teamIds[$away - 1]]
                        : [$teamIds[$away - 1], $teamIds[$home - 1]];

                    $rows[] = [
                        'league_id' => $league->id,
                        'home_team_id' => $homeId,
                        'away_team_id' => $awayId,
                        'stadium_id' => $stadiumIdByTeam[$homeId],
                        'match_date' => $matchDate->format('Y-m-d H:i:s'),
                        'home_score' => $finished ? $this->randomGoals() : null,
                        'away_score' => $finished ? $this->randomGoals() : null,
                        'round' => $round,
                        'group_number' => 1,
                        'status' => $finished ? 'finished' : 'scheduled',
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }
            }
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            Game::insert($chunk);
        }
    }

    private function createEvents(League $league, Collection $playersByTeam, Carbon $now): void
    {
        $games = Game::where('league_id', $league->id)
            ->where('status', 'finished')
            ->get(['id', 'league_id', 'home_team_id', 'away_team_id', 'home_score', 'away_score']);
        $rows = [];

        foreach ($games as $game) {
            foreach ([[$game->home_team_id, $game->home_score], [$game->away_team_id, $game->away_score]] as [$teamId, $goals]) {
                $squad = $playersByTeam->get($teamId);
                array_push($rows, ...$this->goalEvents($game, $teamId, $goals, $squad, $now));
                array_push($rows, ...$this->cardEvents($game, $teamId, $squad, $now));
            }
        }

        foreach (array_chunk($rows, self::CHUNK_SIZE) as $chunk) {
            GameEvent::insert($chunk);
        }
    }

    private function goalEvents(Game $game, int $teamId, int $goals, Collection $squad, Carbon $now): array
    {
        $attackers = $squad->whereIn('position', ['ATA', 'MEI'])->values();
        $events = [];

        for ($i = 0; $i < $goals; $i++) {
            $scorer = $attackers->random();
            $assist = fake()->boolean(40) ? $attackers->where('id', '!=', $scorer->id)->random() : null;
            $events[] = $this->event($game, $teamId, $scorer->id, $assist?->id, 'goal', $now);
        }

        return $events;
    }

    private function cardEvents(Game $game, int $teamId, Collection $squad, Carbon $now): array
    {
        $events = [];
        $yellowCards = fake()->randomElement([1, 2, 2, 3, 3, 4]);

        for ($i = 0; $i < $yellowCards; $i++) {
            $events[] = $this->event($game, $teamId, $squad->random()->id, null, 'yellow_card', $now);
        }

        if (fake()->boolean(10)) {
            $events[] = $this->event($game, $teamId, $squad->random()->id, null, 'red_card', $now);
        }

        return $events;
    }

    private function event(Game $game, int $teamId, int $playerId, ?int $relatedPlayerId, string $type, Carbon $now): array
    {
        return [
            'game_id' => $game->id,
            'team_id' => $teamId,
            'player_id' => $playerId,
            'related_player_id' => $relatedPlayerId,
            'league_id' => $game->league_id,
            'type' => $type,
            'minute' => fake()->numberBetween(1, 90),
            'created_at' => $now,
            'updated_at' => $now,
        ];
    }

    private function randomGoals(): int
    {
        return fake()->randomElement([0, 0, 1, 1, 1, 2, 2, 2, 3, 4]);
    }

    private function firstTurnRounds(array $teams): array
    {
        $count = count($teams);
        $rounds = [];

        for ($round = 0; $round < $count - 1; $round++) {
            $matches = [];

            for ($i = 0; $i < $count / 2; $i++) {
                $pair = [$teams[$i], $teams[$count - 1 - $i]];
                $matches[] = ($round + $i) % 2 === 0 ? $pair : array_reverse($pair);
            }

            $rounds[] = $matches;
            $teams = [$teams[0], $teams[$count - 1], ...array_slice($teams, 1, $count - 2)];
        }

        return $rounds;
    }
}

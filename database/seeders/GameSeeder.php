<?php

namespace Database\Seeders;

use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use App\Models\Team;
use Database\Seeders\Support\FootballData;
use Illuminate\Database\Seeder;
use Illuminate\Support\Carbon;

class GameSeeder extends Seeder
{
    /**
     * Quantidade de rodadas já disputadas (status finished) antes da rodada
     * "atual" de cada liga.
     */
    private const FINISHED_ROUNDS = 6;

    public function run(): void
    {
        $leagueIds = League::pluck('id', 'name');
        $teamIds = Team::pluck('id', 'name');
        $teamCatalog = FootballData::teams();
        $leagueCatalog = FootballData::leagues();
        $stadiumCatalog = FootballData::stadiums();

        foreach (FootballData::leagueTeams() as $leagueKey => $teamKeys) {
            $league = League::find($leagueIds[$leagueCatalog[$leagueKey]['name']]);

            $orderedTeamIds = collect($teamKeys)
                ->map(fn (string $key) => $teamIds[$teamCatalog[$key]['name']])
                ->values()
                ->all();

            $stadiumIdByTeamId = $this->stadiumIdsByTeam($league->id, $teamKeys, $teamIds, $teamCatalog, $stadiumCatalog);

            Game::insert($this->buildRounds($league, $orderedTeamIds, $stadiumIdByTeamId));
        }
    }

    /**
     * Gera as rodadas de turno único (round-robin) e monta as linhas de Game
     * já com status/placar coerentes: rodadas até FINISHED_ROUNDS ficam
     * "finished" com placar, a primeira partida da rodada seguinte fica
     * "in_progress" (com placar parcial), e o restante "scheduled".
     */
    private function buildRounds(League $league, array $teamIds, array $stadiumIdByTeamId): array
    {
        $rounds = $this->roundRobin($teamIds);
        $kickoff = Carbon::parse($league->start_date)->setTime(16, 0);
        $now = now();
        $rows = [];

        foreach ($rounds as $roundIndex => $matches) {
            $roundNumber = $roundIndex + 1;
            $roundDate = $kickoff->copy()->addWeeks($roundIndex);

            foreach ($matches as $matchIndex => [$homeId, $awayId]) {
                [$status, $homeScore, $awayScore, $matchDate] = $this->statusFor($roundNumber, $matchIndex, $roundDate);

                $rows[] = [
                    'league_id' => $league->id,
                    'home_team_id' => $homeId,
                    'away_team_id' => $awayId,
                    'stadium_id' => $stadiumIdByTeamId[$homeId],
                    'match_date' => $matchDate->format('Y-m-d H:i:s'),
                    'home_score' => $homeScore,
                    'away_score' => $awayScore,
                    'round' => $roundNumber,
                    'status' => $status,
                    'created_at' => $now,
                    'updated_at' => $now,
                ];
            }
        }

        return $rows;
    }

    private function statusFor(int $roundNumber, int $matchIndex, Carbon $roundDate): array
    {
        if ($roundNumber <= self::FINISHED_ROUNDS) {
            return ['finished', $this->randomGoals(), $this->randomGoals(), $roundDate];
        }

        if ($roundNumber === self::FINISHED_ROUNDS + 1 && $matchIndex === 0) {
            return ['in_progress', fake()->numberBetween(0, 2), fake()->numberBetween(0, 2), now()];
        }

        return ['scheduled', null, null, $roundDate];
    }

    private function randomGoals(): int
    {
        return fake()->randomElement([0, 0, 1, 1, 1, 2, 2, 2, 3, 4]);
    }

    private function stadiumIdsByTeam(int $leagueId, array $teamKeys, $teamIds, array $teamCatalog, array $stadiumCatalog): array
    {
        $stadiumsByName = Stadium::where('league_id', $leagueId)->get()->keyBy('name');
        $result = [];

        foreach ($teamKeys as $teamKey) {
            $teamId = $teamIds[$teamCatalog[$teamKey]['name']];
            $stadiumName = $stadiumCatalog[$teamKey]['name'];
            $result[$teamId] = $stadiumsByName[$stadiumName]->id;
        }

        return $result;
    }

    /**
     * Método do círculo: gera n-1 rodadas para n times (par), cada uma com
     * n/2 confrontos, sem repetir nenhum par ao longo do turno único.
     */
    private function roundRobin(array $teamIds): array
    {
        $list = $teamIds;
        $n = count($list);
        $half = intdiv($n, 2);
        $rounds = [];

        for ($round = 0; $round < $n - 1; $round++) {
            $matches = [];

            for ($i = 0; $i < $half; $i++) {
                $home = $list[$i];
                $away = $list[$n - 1 - $i];

                if ($round % 2 === 1) {
                    [$home, $away] = [$away, $home];
                }

                $matches[] = [$home, $away];
            }

            $rounds[] = $matches;

            $fixed = $list[0];
            $rest = array_slice($list, 1);
            array_unshift($rest, array_pop($rest));
            $list = array_merge([$fixed], $rest);
        }

        return $rounds;
    }
}

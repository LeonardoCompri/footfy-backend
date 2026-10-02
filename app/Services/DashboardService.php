<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    private const RECENT_GAMES_LIMIT = 5;

    public function __construct(
        private readonly TeamService $teamService,
        private readonly GameService $gameService,
        private readonly StadiumService $stadiumService,
        private readonly StatisticsService $statisticsService,
    ) {}

    /**
     * Monta o Dashboard do admin para uma liga: contadores do cabeçalho, últimos
     * resultados/próximas partidas e os gráficos (`goals_by_bucket`/`results_split`).
     * Reaproveita TeamService/GameService/StadiumService (já validam acesso por liga) e
     * StatisticsService — nenhuma query nova de agregação é escrita aqui, só composição.
     */
    public function build(League $league): array
    {
        $leagueId = (string) $league->id;

        $teams = $this->teamService->index($leagueId);
        $games = $this->gameService->index($leagueId);
        $stadiums = $this->stadiumService->index($leagueId);
        $statistics = $this->statisticsService->build($league);

        return [
            'counters' => [
                'teams' => $teams->count(),
                'games' => $games->count(),
                'stadiums' => $stadiums->count(),
                'season' => $league->season,
            ],
            'last_results' => $this->lastResults($games),
            'upcoming_games' => $this->upcomingGames($games),
            'goals_by_bucket' => $statistics['goals_by_bucket'],
            'results_split' => $statistics['results_split'],
        ];
    }

    // Últimas partidas finalizadas da liga, mais recentes primeiro.
    private function lastResults(Collection $games): array
    {
        $games = $games->where('status', 'finished')
            ->sortByDesc('match_date')
            ->take(self::RECENT_GAMES_LIMIT)
            ->values();

        $games->load(['homeTeam', 'awayTeam']);

        return $games->map(fn (Game $game) => $this->summarizeGame($game))->all();
    }

    // Próximas partidas agendadas da liga, mais próximas primeiro.
    private function upcomingGames(Collection $games): array
    {
        $games = $games->where('status', 'scheduled')
            ->sortBy('match_date')
            ->take(self::RECENT_GAMES_LIMIT)
            ->values();

        $games->load(['homeTeam', 'awayTeam']);

        return $games->map(fn (Game $game) => $this->summarizeGame($game))->all();
    }

    // Resumo leve da partida para as listas do Dashboard — mesmos campos usados pelas
    // "legs" de confronto do StandingsService, sem passar pelo GameResource completo
    // (eventos/estádio não são exibidos nessas listas).
    private function summarizeGame(Game $game): array
    {
        return [
            'id' => $game->id,
            'home_team' => $this->teamSummary($game->homeTeam),
            'away_team' => $this->teamSummary($game->awayTeam),
            'home_score' => $game->home_score,
            'away_score' => $game->away_score,
            'status' => $game->status,
            'match_date' => $game->match_date,
            'round' => $game->round,
            'phase' => $game->phase,
        ];
    }

    private function teamSummary(?Team $team): ?array
    {
        return $team
            ? ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logo]
            : null;
    }
}

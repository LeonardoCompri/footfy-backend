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
            'totals' => $statistics['totals'],
            'cards_split' => $statistics['cards_split'],
        ];
    }

    private function lastResults(Collection $games): array
    {
        $games = $games->where('status', 'finished')
            ->sortByDesc('match_date')
            ->take(self::RECENT_GAMES_LIMIT)
            ->values();

        $games->load(['homeTeam', 'awayTeam']);

        return $games->map(fn (Game $game) => $this->summarizeGame($game))->all();
    }

    private function upcomingGames(Collection $games): array
    {
        $games = $games->where('status', 'scheduled')
            ->sortBy('match_date')
            ->take(self::RECENT_GAMES_LIMIT)
            ->values();

        $games->load(['homeTeam', 'awayTeam']);

        return $games->map(fn (Game $game) => $this->summarizeGame($game))->all();
    }

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
            ? ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logoUrl()]
            : null;
    }
}

<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Repositories\GameRepository;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Collection;

class DashboardService
{
    private const RECENT_GAMES_LIMIT = 5;

    private const COUNTED_RELATIONS = ['teams', 'games', 'stadiums'];

    public function __construct(
        private readonly LeagueRepository $leagueRepository,
        private readonly GameRepository $gameRepository,
        private readonly StatisticsService $statisticsService,
    ) {}

    public function build(League $league): array
    {
        $leagueId = (int) $league->id;
        $league = $this->leagueRepository->loadCounts($league, self::COUNTED_RELATIONS);

        return [
            'counters' => [
                'teams' => (int) $league->teams_count,
                'games' => (int) $league->games_count,
                'stadiums' => (int) $league->stadiums_count,
                'season' => $league->season,
            ],
            'last_results' => $this->summarizeGames($this->gameRepository->latestFinished($leagueId, self::RECENT_GAMES_LIMIT)),
            'upcoming_games' => $this->summarizeGames($this->gameRepository->nextScheduled($leagueId, self::RECENT_GAMES_LIMIT)),
            ...$this->statisticsService->summary($league),
        ];
    }

    private function summarizeGames(Collection $games): array
    {
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

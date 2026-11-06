<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StandingsRepository
{
    public function leagueTeams(int $leagueId): EloquentCollection
    {
        return Team::query()
            ->whereHas('leagues', fn ($q) => $q->where('leagues.id', $leagueId))
            ->get(['id', 'name', 'nickname', 'logo']);
    }

    public function groupStageAggregates(int $leagueId): Collection
    {
        $base = fn () => Game::query()
            ->where('league_id', $leagueId)
            ->whereNotNull('round')
            ->where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score');

        $home = $base()->selectRaw(
            'home_team_id as team_id, home_score as gf, away_score as ga, '.
            'CASE WHEN home_score > away_score THEN 1 ELSE 0 END as win, '.
            'CASE WHEN home_score = away_score THEN 1 ELSE 0 END as draw, '.
            'CASE WHEN home_score < away_score THEN 1 ELSE 0 END as loss'
        );

        $away = $base()->selectRaw(
            'away_team_id as team_id, away_score as gf, home_score as ga, '.
            'CASE WHEN away_score > home_score THEN 1 ELSE 0 END as win, '.
            'CASE WHEN away_score = home_score THEN 1 ELSE 0 END as draw, '.
            'CASE WHEN away_score < home_score THEN 1 ELSE 0 END as loss'
        );

        return DB::query()
            ->fromSub($home->unionAll($away), 'results')
            ->selectRaw(
                'team_id, COUNT(*) as played, SUM(win) as wins, SUM(draw) as draws, SUM(loss) as losses, '.
                'SUM(gf) as goals_for, SUM(ga) as goals_against, SUM(win) * 3 + SUM(draw) as points'
            )
            ->groupBy('team_id')
            ->get();
    }

    public function groupStageCardCounts(int $leagueId): array
    {
        return GameEvent::query()
            ->joinEventType()
            ->join('games', 'games.id', '=', 'game_events.game_id')
            ->where('game_events.league_id', $leagueId)
            ->whereNotNull('games.round')
            ->whereNotNull('game_event_types.card')
            ->groupBy('game_events.team_id')
            ->selectRaw('game_events.team_id as team_id, COUNT(*) as cards')
            ->pluck('cards', 'team_id')
            ->toArray();
    }

    public function headToHeadGames(int $leagueId, int $teamAId, int $teamBId): EloquentCollection
    {
        return Game::query()
            ->where('league_id', $leagueId)
            ->whereNotNull('round')
            ->where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->where(function ($q) use ($teamAId, $teamBId) {
                $q->where(fn ($q2) => $q2->where('home_team_id', $teamAId)->where('away_team_id', $teamBId))
                    ->orWhere(fn ($q2) => $q2->where('home_team_id', $teamBId)->where('away_team_id', $teamAId));
            })
            ->get();
    }

    public function knockoutGames(int $leagueId): EloquentCollection
    {
        return Game::query()
            ->with(['homeTeam', 'awayTeam'])
            ->where('league_id', $leagueId)
            ->whereNotNull('phase')
            ->orderBy('phase_order')
            ->orderBy('tie_key')
            ->orderBy('leg')
            ->get();
    }
}

<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class StandingsRepository
{
    public function leagueTeams(int $leagueId): EloquentCollection
    {
        return Team::query()
            ->join('league_team', 'league_team.team_id', '=', 'teams.id')
            ->where('league_team.league_id', $leagueId)
            ->orderBy('teams.id')
            ->get(['teams.id', 'teams.name', 'teams.nickname', 'teams.logo', 'league_team.group_number']);
    }

    public function groupStageGames(int $leagueId): Collection
    {
        return Game::query()
            ->where('league_id', $leagueId)
            ->whereNotNull('round')
            ->orderBy('id')
            ->toBase()
            ->get(['id', 'home_team_id', 'away_team_id', 'home_score', 'away_score', 'status', 'group_number']);
    }

    public function groupStageCards(int $leagueId): Collection
    {
        return GameEvent::query()
            ->joinEventType()
            ->join('games', 'games.id', '=', 'game_events.game_id')
            ->where('game_events.league_id', $leagueId)
            ->whereNotNull('games.round')
            ->whereNotNull('game_event_types.card')
            ->groupBy('game_events.game_id', 'game_events.team_id', 'game_event_types.card')
            ->selectRaw('game_events.game_id as game_id, game_events.team_id as team_id, game_event_types.card as card, COUNT(*) as total')
            ->toBase()
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

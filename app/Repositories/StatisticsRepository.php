<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StatisticsRepository
{
    public function topScorers(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->joinEventType()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_event_types.counts_for', 'team')
            ->groupBy('game_events.player_id', 'players.name', 'players.team_id', 'players.deleted_at', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.player_id as player_id, players.name as player_name, players.deleted_at as player_deleted_at, '.
                'players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, COUNT(*) as goals'
            )
            ->orderByDesc('goals')
            ->limit($limit)
            ->get();
    }

    public function topAssists(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->joinEventType()
            ->join('players', 'players.id', '=', 'game_events.related_player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_event_types.counts_for', 'team')
            ->whereNotNull('game_events.related_player_id')
            ->groupBy('game_events.related_player_id', 'players.name', 'players.team_id', 'players.deleted_at', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.related_player_id as player_id, players.name as player_name, players.deleted_at as player_deleted_at, '.
                'players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, COUNT(*) as assists'
            )
            ->orderByDesc('assists')
            ->limit($limit)
            ->get();
    }

    public function topCards(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->joinEventType()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->whereIn('game_event_types.card', GameEventType::CARDS)
            ->groupBy('game_events.player_id', 'players.name', 'players.team_id', 'players.deleted_at', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.player_id as player_id, players.name as player_name, players.deleted_at as player_deleted_at, '.
                'players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, '.
                "SUM(CASE WHEN game_event_types.card = 'yellow' THEN 1 ELSE 0 END) as yellow_cards, ".
                "SUM(CASE WHEN game_event_types.card = 'red' THEN 1 ELSE 0 END) as red_cards, ".
                'COUNT(*) as total_cards'
            )
            ->orderByDesc('total_cards')
            ->limit($limit)
            ->get();
    }

    public function cardsSplit(int $leagueId): object
    {
        return GameEvent::query()
            ->joinEventType()
            ->where('game_events.league_id', $leagueId)
            ->whereIn('game_event_types.card', GameEventType::CARDS)
            ->selectRaw(
                "SUM(CASE WHEN game_event_types.card = 'yellow' THEN 1 ELSE 0 END) as yellow, ".
                "SUM(CASE WHEN game_event_types.card = 'red' THEN 1 ELSE 0 END) as red"
            )
            ->first();
    }

    public function goalsByRound(int $leagueId): Collection
    {
        return $this->finishedGamesWithScore($leagueId)
            ->whereNotNull('round')
            ->groupBy('round')
            ->selectRaw('games.round as round, SUM(games.home_score + games.away_score) as goals')
            ->orderBy('round')
            ->get();
    }

    public function goalsByPhase(int $leagueId): Collection
    {
        return $this->finishedGamesWithScore($leagueId)
            ->whereNotNull('phase')
            ->groupBy('phase', 'phase_order')
            ->selectRaw('games.phase as phase, games.phase_order as phase_order, SUM(games.home_score + games.away_score) as goals')
            ->orderBy('phase_order')
            ->get();
    }

    public function finishedGamesSummary(int $leagueId): object
    {
        return $this->finishedGamesWithScore($leagueId)
            ->selectRaw(
                'SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as home_wins, '.
                'SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) as draws, '.
                'SUM(CASE WHEN home_score < away_score THEN 1 ELSE 0 END) as away_wins, '.
                'SUM(home_score + away_score) as goals, '.
                'COUNT(*) as finished_games'
            )
            ->first();
    }

    private function finishedGamesWithScore(int $leagueId): Builder
    {
        return Game::query()
            ->where('league_id', $leagueId)
            ->where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score');
    }
}

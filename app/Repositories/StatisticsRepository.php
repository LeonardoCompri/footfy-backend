<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GameEventType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class StatisticsRepository
{
    public function topScorers(int $leagueId, int $limit = 10): Collection
    {
        $counts = $this->leagueEventsByPlayer($leagueId, 'game_events.player_id')
            ->where('game_event_types.counts_for', 'team')
            ->selectRaw('COUNT(*) as goals');

        return $this->playerRanking($counts, 'goals', $limit);
    }

    public function topAssists(int $leagueId, int $limit = 10): Collection
    {
        $counts = $this->leagueEventsByPlayer($leagueId, 'game_events.related_player_id')
            ->where('game_event_types.counts_for', 'team')
            ->whereNotNull('game_events.related_player_id')
            ->selectRaw('COUNT(*) as assists');

        return $this->playerRanking($counts, 'assists', $limit);
    }

    public function topCards(int $leagueId, int $limit = 10): Collection
    {
        $counts = $this->leagueEventsByPlayer($leagueId, 'game_events.player_id')
            ->whereIn('game_event_types.card', GameEventType::CARDS)
            ->selectRaw(
                "SUM(CASE WHEN game_event_types.card = 'yellow' THEN 1 ELSE 0 END) as yellow_cards, ".
                "SUM(CASE WHEN game_event_types.card = 'red' THEN 1 ELSE 0 END) as red_cards, ".
                'COUNT(*) as total_cards'
            );

        return $this->playerRanking($counts, 'total_cards', $limit);
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

    private function leagueEventsByPlayer(int $leagueId, string $playerColumn): Builder
    {
        return GameEvent::query()
            ->joinEventType()
            ->where('game_events.league_id', $leagueId)
            ->groupBy($playerColumn)
            ->select("{$playerColumn} as player_id");
    }

    private function playerRanking(Builder $counts, string $countColumn, int $limit): Collection
    {
        $ranked = DB::table('players')
            ->joinSub($counts, 'counts', 'counts.player_id', '=', 'players.id')
            ->select('counts.*', 'players.name as player_name', 'players.deleted_at as player_deleted_at', 'players.team_id as team_id')
            ->orderByDesc("counts.{$countColumn}")
            ->orderBy('players.name')
            ->orderBy('counts.player_id')
            ->limit($limit);

        return DB::query()
            ->fromSub($ranked, 'ranked')
            ->leftJoin('teams', 'teams.id', '=', 'ranked.team_id')
            ->select('ranked.*', 'teams.name as team_name', 'teams.nickname as team_nickname')
            ->orderByDesc("ranked.{$countColumn}")
            ->orderBy('ranked.player_name')
            ->orderBy('ranked.player_id')
            ->get();
    }
}

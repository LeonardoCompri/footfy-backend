<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use Illuminate\Support\Collection;

class StatisticsRepository
{
    /**
     * Artilheiros da liga inteira (grupos + mata-mata), agregados por jogador — soma eventos
     * `goal` de todos os times pelos quais o jogador atuou na liga (cobre transferência entre
     * times da mesma liga). `team_id`/`team_name` refletem o time atual do jogador
     * (players.team_id), só para exibição, sem afetar a soma de gols.
     */
    public function topScorers(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
            ->groupBy('game_events.player_id', 'players.name', 'players.team_id', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.player_id as player_id, players.name as player_name, players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, COUNT(*) as goals'
            )
            ->orderByDesc('goals')
            ->limit($limit)
            ->get();
    }

    // Mesma lógica de topScorers, mas para assistências (related_player_id de eventos `goal`).
    public function topAssists(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.related_player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
            ->whereNotNull('game_events.related_player_id')
            ->groupBy('game_events.related_player_id', 'players.name', 'players.team_id', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.related_player_id as player_id, players.name as player_name, players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, COUNT(*) as assists'
            )
            ->orderByDesc('assists')
            ->limit($limit)
            ->get();
    }

    // Ranking de cartões (amarelo+vermelho) por jogador, somando toda a competição.
    public function topCards(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->whereIn('game_events.type', ['yellow_card', 'red_card'])
            ->groupBy('game_events.player_id', 'players.name', 'players.team_id', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.player_id as player_id, players.name as player_name, players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, '.
                "SUM(CASE WHEN game_events.type = 'yellow_card' THEN 1 ELSE 0 END) as yellow_cards, ".
                "SUM(CASE WHEN game_events.type = 'red_card' THEN 1 ELSE 0 END) as red_cards, ".
                'COUNT(*) as total_cards'
            )
            ->orderByDesc('total_cards')
            ->limit($limit)
            ->get();
    }

    // Gols por rodada (jogos com `round` preenchido), para o gráfico do Dashboard.
    public function goalsByRound(int $leagueId): Collection
    {
        return GameEvent::query()
            ->join('games', 'games.id', '=', 'game_events.game_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
            ->whereNotNull('games.round')
            ->groupBy('games.round')
            ->selectRaw('games.round as round, COUNT(*) as goals')
            ->orderBy('games.round')
            ->get();
    }

    // Gols por fase (jogos com `phase` preenchida), para ligas knockout/hybrid.
    public function goalsByPhase(int $leagueId): Collection
    {
        return GameEvent::query()
            ->join('games', 'games.id', '=', 'game_events.game_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
            ->whereNotNull('games.phase')
            ->groupBy('games.phase', 'games.phase_order')
            ->selectRaw('games.phase as phase, games.phase_order as phase_order, COUNT(*) as goals')
            ->orderBy('games.phase_order')
            ->get();
    }

    // Contagem de vitória mandante / empate / vitória visitante entre as partidas finalizadas
    // da liga inteira (grupos + mata-mata).
    public function resultsSplit(int $leagueId): object
    {
        return Game::query()
            ->where('league_id', $leagueId)
            ->where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score')
            ->selectRaw(
                'SUM(CASE WHEN home_score > away_score THEN 1 ELSE 0 END) as home_wins, '.
                'SUM(CASE WHEN home_score = away_score THEN 1 ELSE 0 END) as draws, '.
                'SUM(CASE WHEN home_score < away_score THEN 1 ELSE 0 END) as away_wins'
            )
            ->first();
    }
}

<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;

class StatisticsRepository
{
    /**
     * Artilheiros da liga inteira (grupos + mata-mata), agregados por jogador — soma eventos
     * `goal` de todos os times pelos quais o jogador atuou na liga (cobre transferência entre
     * times da mesma liga). `team_id`/`team_name` refletem o time atual do jogador
     * (players.team_id), só para exibição, sem afetar a soma de gols. O JOIN em players ignora
     * o soft delete de propósito: jogador removido continua no ranking, com `player_deleted_at`
     * preenchido (vira `player_removed` no StatisticsService). `players.deleted_at` entra no
     * groupBy por causa do ONLY_FULL_GROUP_BY do MySQL.
     */
    public function topScorers(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
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

    // Mesma lógica de topScorers (inclusive jogador removido no ranking), mas para assistências
    // (related_player_id de eventos `goal`).
    public function topAssists(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.related_player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->where('game_events.type', 'goal')
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

    // Ranking de cartões (amarelo+vermelho) por jogador, somando toda a competição; jogador
    // removido continua no ranking, igual a topScorers.
    public function topCards(int $leagueId, int $limit = 10): Collection
    {
        return GameEvent::query()
            ->join('players', 'players.id', '=', 'game_events.player_id')
            ->leftJoin('teams', 'teams.id', '=', 'players.team_id')
            ->where('game_events.league_id', $leagueId)
            ->whereIn('game_events.type', ['yellow_card', 'red_card'])
            ->groupBy('game_events.player_id', 'players.name', 'players.team_id', 'players.deleted_at', 'teams.name', 'teams.nickname')
            ->selectRaw(
                'game_events.player_id as player_id, players.name as player_name, players.deleted_at as player_deleted_at, '.
                'players.team_id as team_id, '.
                'teams.name as team_name, teams.nickname as team_nickname, '.
                "SUM(CASE WHEN game_events.type = 'yellow_card' THEN 1 ELSE 0 END) as yellow_cards, ".
                "SUM(CASE WHEN game_events.type = 'red_card' THEN 1 ELSE 0 END) as red_cards, ".
                'COUNT(*) as total_cards'
            )
            ->orderByDesc('total_cards')
            ->limit($limit)
            ->get();
    }

    /**
     * Total de cartões amarelos/vermelhos da liga inteira, sem limite (diferente de topCards,
     * que é um ranking top N). Uma única query agregada, coberta pelo índice
     * game_events(league_id, type).
     */
    public function cardsSplit(int $leagueId): object
    {
        return GameEvent::query()
            ->where('league_id', $leagueId)
            ->whereIn('type', ['yellow_card', 'red_card'])
            ->selectRaw(
                "SUM(CASE WHEN type = 'yellow_card' THEN 1 ELSE 0 END) as yellow, ".
                "SUM(CASE WHEN type = 'red_card' THEN 1 ELSE 0 END) as red"
            )
            ->first();
    }

    /**
     * Gols por rodada (jogos com `round` preenchido), para o gráfico do Dashboard. Vem do
     * placar (`home_score + away_score`) das partidas finalizadas com placar preenchido, não
     * dos eventos `goal` — assim liga sem súmula lançada não aparece com 0 gols.
     */
    public function goalsByRound(int $leagueId): Collection
    {
        return $this->finishedGamesWithScore($leagueId)
            ->whereNotNull('round')
            ->groupBy('round')
            ->selectRaw('games.round as round, SUM(games.home_score + games.away_score) as goals')
            ->orderBy('round')
            ->get();
    }

    // Mesma fonte de goalsByRound (placar das partidas finalizadas), agrupada por fase
    // (jogos com `phase` preenchida), para ligas knockout/hybrid.
    public function goalsByPhase(int $leagueId): Collection
    {
        return $this->finishedGamesWithScore($leagueId)
            ->whereNotNull('phase')
            ->groupBy('phase', 'phase_order')
            ->selectRaw('games.phase as phase, games.phase_order as phase_order, SUM(games.home_score + games.away_score) as goals')
            ->orderBy('phase_order')
            ->get();
    }

    /**
     * Resumo das partidas finalizadas com placar da liga inteira (grupos + mata-mata), numa
     * única query: vitória mandante / empate / vitória visitante, total de gols pelo placar
     * (`goals`) e quantidade de partidas (`finished_games`). Sem partidas, os SUMs vêm `null`
     * e `finished_games` vem 0.
     */
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

    // Partidas da liga que contam para os totais de gols/resultados: finalizadas e com
    // placar preenchido (partida agendada com placar não entra).
    private function finishedGamesWithScore(int $leagueId): Builder
    {
        return Game::query()
            ->where('league_id', $leagueId)
            ->where('status', 'finished')
            ->whereNotNull('home_score')
            ->whereNotNull('away_score');
    }
}

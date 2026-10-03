<?php

namespace App\Services;

use App\Models\League;
use App\Repositories\StatisticsRepository;
use Illuminate\Support\Collection;

class StatisticsService
{
    private const RANKING_LIMIT = 10;

    public function __construct(private readonly StatisticsRepository $repository) {}

    /**
     * Estatísticas da liga inteira (grupos + mata-mata somados, mesmo em liga `hybrid` —
     * diferente da Tabela da Liga, que isola as duas fases). Reaproveitado tanto pela rota
     * admin quanto pela pública (e pelo Dashboard, que usa `goals_by_bucket`/`results_split`/`cards_split`
     * desta mesma resposta), seguindo o mesmo princípio do StandingsService.
     */
    public function build(League $league): array
    {
        $leagueId = (int) $league->id;

        return [
            'top_scorers' => $this->mapPlayerRanking($this->repository->topScorers($leagueId, self::RANKING_LIMIT), 'goals'),
            'top_assists' => $this->mapPlayerRanking($this->repository->topAssists($leagueId, self::RANKING_LIMIT), 'assists'),
            'top_cards' => $this->mapCardRanking($this->repository->topCards($leagueId, self::RANKING_LIMIT)),
            'goals_by_bucket' => $this->goalsByBucket($leagueId),
            'results_split' => $this->resultsSplit($leagueId),
            'cards_split' => $this->cardsSplit($leagueId),
        ];
    }

    private function mapPlayerRanking(Collection $rows, string $countKey): array
    {
        return $rows->map(fn ($row) => [
            'player_id' => (int) $row->player_id,
            'player_name' => $row->player_name,
            'team_id' => $row->team_id !== null ? (int) $row->team_id : null,
            'team_name' => $row->team_name,
            'team_nickname' => $row->team_nickname,
            $countKey => (int) $row->{$countKey},
        ])->all();
    }

    private function mapCardRanking(Collection $rows): array
    {
        return $rows->map(fn ($row) => [
            'player_id' => (int) $row->player_id,
            'player_name' => $row->player_name,
            'team_id' => $row->team_id !== null ? (int) $row->team_id : null,
            'team_name' => $row->team_name,
            'team_nickname' => $row->team_nickname,
            'yellow_cards' => (int) $row->yellow_cards,
            'red_cards' => (int) $row->red_cards,
            'total_cards' => (int) $row->total_cards,
        ])->all();
    }

    /**
     * Gols agrupados por rodada (jogos com `round`) seguidos dos agrupados por fase (jogos
     * com `phase`) — cobre round_robin (só rodadas), knockout (só fases) e hybrid (os dois,
     * já que uma liga híbrida tem jogos de grupo com `round` e jogos de mata-mata com `phase`
     * misturados na mesma competição).
     */
    private function goalsByBucket(int $leagueId): array
    {
        $byRound = $this->repository->goalsByRound($leagueId)->map(fn ($row) => [
            'type' => 'round',
            'round' => (int) $row->round,
            'phase' => null,
            'phase_order' => null,
            'goals' => (int) $row->goals,
        ]);

        $byPhase = $this->repository->goalsByPhase($leagueId)->map(fn ($row) => [
            'type' => 'phase',
            'round' => null,
            'phase' => $row->phase,
            'phase_order' => $row->phase_order !== null ? (int) $row->phase_order : null,
            'goals' => (int) $row->goals,
        ]);

        return $byRound->concat($byPhase)->values()->all();
    }

    private function resultsSplit(int $leagueId): array
    {
        $row = $this->repository->resultsSplit($leagueId);

        return [
            'home_wins' => (int) ($row->home_wins ?? 0),
            'draws' => (int) ($row->draws ?? 0),
            'away_wins' => (int) ($row->away_wins ?? 0),
        ];
    }

    // Total de cartões da liga inteira (sem o limite de RANKING_LIMIT de `top_cards`).
    private function cardsSplit(int $leagueId): array
    {
        $row = $this->repository->cardsSplit($leagueId);

        return [
            'yellow' => (int) ($row->yellow ?? 0),
            'red' => (int) ($row->red ?? 0),
        ];
    }
}

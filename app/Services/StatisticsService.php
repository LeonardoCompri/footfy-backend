<?php

namespace App\Services;

use App\Models\League;
use App\Repositories\StatisticsRepository;
use Illuminate\Support\Collection;

class StatisticsService
{
    private const RANKING_LIMIT = 10;

    public function __construct(private readonly StatisticsRepository $repository) {}

    public function build(League $league): array
    {
        $leagueId = (int) $league->id;
        $summary = $this->repository->finishedGamesSummary($leagueId);

        return [
            'top_scorers' => $this->mapPlayerRanking($this->repository->topScorers($leagueId, self::RANKING_LIMIT), 'goals'),
            'top_assists' => $this->mapPlayerRanking($this->repository->topAssists($leagueId, self::RANKING_LIMIT), 'assists'),
            'top_cards' => $this->mapCardRanking($this->repository->topCards($leagueId, self::RANKING_LIMIT)),
            'goals_by_bucket' => $this->goalsByBucket($leagueId),
            'results_split' => $this->resultsSplit($summary),
            'totals' => $this->totals($summary),
            'cards_split' => $this->cardsSplit($leagueId),
        ];
    }

    private function mapPlayerRanking(Collection $rows, string $countKey): array
    {
        return $rows->map(fn ($row) => [
            'player_id' => (int) $row->player_id,
            'player_name' => $row->player_name,
            'player_removed' => $row->player_deleted_at !== null,
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
            'player_removed' => $row->player_deleted_at !== null,
            'team_id' => $row->team_id !== null ? (int) $row->team_id : null,
            'team_name' => $row->team_name,
            'team_nickname' => $row->team_nickname,
            'yellow_cards' => (int) $row->yellow_cards,
            'red_cards' => (int) $row->red_cards,
            'total_cards' => (int) $row->total_cards,
        ])->all();
    }

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

    private function resultsSplit(?object $summary): array
    {
        return [
            'home_wins' => (int) ($summary->home_wins ?? 0),
            'draws' => (int) ($summary->draws ?? 0),
            'away_wins' => (int) ($summary->away_wins ?? 0),
        ];
    }

    private function totals(?object $summary): array
    {
        $goals = (int) ($summary->goals ?? 0);
        $finishedGames = (int) ($summary->finished_games ?? 0);

        return [
            'goals' => $goals,
            'finished_games' => $finishedGames,
            'goals_average' => $finishedGames > 0 ? round($goals / $finishedGames, 2) : null,
        ];
    }

    private function cardsSplit(int $leagueId): array
    {
        $row = $this->repository->cardsSplit($leagueId);

        return [
            'yellow' => (int) ($row->yellow ?? 0),
            'red' => (int) ($row->red ?? 0),
        ];
    }
}

<?php

namespace App\Services\Knockout;

use App\Models\Game;

final class TieResolver
{
    public const PENALTIES = 'penalties';

    public function resolve(array $legs, array $sequence, array $seeds = []): array
    {
        $legs = array_values($legs);
        $twoLegged = $this->isTwoLegged($legs);
        $decisive = $this->decisiveLeg($legs);
        $finished = $decisive !== null && $this->allLegsFinished($legs, $twoLegged);

        $result = [
            'finished' => $finished,
            'winner_team_id' => null,
            'loser_team_id' => null,
            'decided_by' => null,
            'awaiting_penalties' => false,
            'penalties_reachable' => false,
            'decisive_game_id' => $decisive['id'] ?? null,
            'home_penalties' => $decisive['home_penalties'] ?? null,
            'away_penalties' => $decisive['away_penalties'] ?? null,
            'aggregate' => $this->aggregate($legs),
        ];

        if (! $finished) {
            return $result;
        }

        $teamA = (int) $decisive['home_team_id'];
        $teamB = (int) $decisive['away_team_id'];
        $aggregate = $result['aggregate'];

        if (($aggregate[$teamA] ?? 0) !== ($aggregate[$teamB] ?? 0)) {
            return $this->withWinner($result, ($aggregate[$teamA] ?? 0) > ($aggregate[$teamB] ?? 0) ? $teamA : $teamB, $teamA, $teamB, 'aggregate');
        }

        foreach ($sequence as $criterion) {
            if ($criterion === 'away_goals' && $twoLegged) {
                $awayGoals = $this->awayGoals($legs);
                $goalsA = $awayGoals[$teamA] ?? 0;
                $goalsB = $awayGoals[$teamB] ?? 0;

                if ($goalsA !== $goalsB) {
                    return $this->withWinner($result, $goalsA > $goalsB ? $teamA : $teamB, $teamA, $teamB, 'away_goals');
                }
            }

            if ($criterion === 'better_campaign' && isset($seeds[$teamA], $seeds[$teamB]) && $seeds[$teamA] !== $seeds[$teamB]) {
                return $this->withWinner($result, $seeds[$teamA] < $seeds[$teamB] ? $teamA : $teamB, $teamA, $teamB, 'better_campaign');
            }

            if ($criterion === self::PENALTIES) {
                $result['penalties_reachable'] = true;
                $home = $decisive['home_penalties'];
                $away = $decisive['away_penalties'];

                if ($home !== null && $away !== null && (int) $home !== (int) $away) {
                    return $this->withWinner($result, (int) $home > (int) $away ? $teamA : $teamB, $teamA, $teamB, 'penalties');
                }

                $result['awaiting_penalties'] = true;

                return $result;
            }
        }

        return $result;
    }

    public function legFromGame(Game $game): array
    {
        return [
            'id' => (int) $game->id,
            'leg' => $game->leg === null ? null : (int) $game->leg,
            'home_team_id' => (int) $game->home_team_id,
            'away_team_id' => (int) $game->away_team_id,
            'home_score' => $game->home_score === null ? null : (int) $game->home_score,
            'away_score' => $game->away_score === null ? null : (int) $game->away_score,
            'home_penalties' => $game->home_penalties === null ? null : (int) $game->home_penalties,
            'away_penalties' => $game->away_penalties === null ? null : (int) $game->away_penalties,
            'status' => $game->status,
        ];
    }

    public function isTwoLegged(array $legs): bool
    {
        foreach ($legs as $leg) {
            if ($leg['leg'] !== null) {
                return true;
            }
        }

        return false;
    }

    public function decisiveLeg(array $legs): ?array
    {
        if (! $this->isTwoLegged($legs)) {
            return count($legs) === 1 ? array_values($legs)[0] : null;
        }

        foreach ($legs as $leg) {
            if ((int) $leg['leg'] === 2) {
                return $leg;
            }
        }

        return null;
    }

    private function allLegsFinished(array $legs, bool $twoLegged): bool
    {
        $expected = $twoLegged ? [1, 2] : [null];
        $found = array_map(fn (array $leg) => $leg['leg'] === null ? null : (int) $leg['leg'], $legs);
        sort($found);

        if ($found !== $expected) {
            return false;
        }

        foreach ($legs as $leg) {
            if (! $this->isLegFinished($leg)) {
                return false;
            }
        }

        return true;
    }

    private function isLegFinished(array $leg): bool
    {
        return $leg['status'] === 'finished' && $leg['home_score'] !== null && $leg['away_score'] !== null;
    }

    private function aggregate(array $legs): array
    {
        $aggregate = [];

        foreach ($legs as $leg) {
            $aggregate[(int) $leg['home_team_id']] ??= 0;
            $aggregate[(int) $leg['away_team_id']] ??= 0;

            if (! $this->isLegFinished($leg)) {
                continue;
            }

            $aggregate[(int) $leg['home_team_id']] += (int) $leg['home_score'];
            $aggregate[(int) $leg['away_team_id']] += (int) $leg['away_score'];
        }

        return $aggregate;
    }

    private function awayGoals(array $legs): array
    {
        $goals = [];

        foreach ($legs as $leg) {
            $awayTeamId = (int) $leg['away_team_id'];
            $goals[$awayTeamId] = ($goals[$awayTeamId] ?? 0) + (int) $leg['away_score'];
        }

        return $goals;
    }

    private function withWinner(array $result, int $winnerId, int $teamA, int $teamB, string $decidedBy): array
    {
        return [
            ...$result,
            'winner_team_id' => $winnerId,
            'loser_team_id' => $winnerId === $teamA ? $teamB : $teamA,
            'decided_by' => $decidedBy,
        ];
    }
}

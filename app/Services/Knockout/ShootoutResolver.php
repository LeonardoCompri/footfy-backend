<?php

namespace App\Services\Knockout;

final class ShootoutResolver
{
    public const REGULATION_KICKS = 5;

    public const MAX_KICKS = 60;

    public function resolve(array $kicks, int $homeTeamId, int $awayTeamId): array
    {
        $score = [$homeTeamId => 0, $awayTeamId => 0];
        $taken = [$homeTeamId => 0, $awayTeamId => 0];
        $decidedAt = null;
        $winner = null;

        foreach (array_values($kicks) as $index => $kick) {
            $teamId = (int) $kick['team_id'];

            if (! array_key_exists($teamId, $score)) {
                continue;
            }

            $taken[$teamId]++;
            $score[$teamId] += $kick['scored'] ? 1 : 0;

            if ($decidedAt === null && $this->isDecided($score, $taken, $homeTeamId, $awayTeamId)) {
                $decidedAt = $index + 1;
                $winner = $score[$homeTeamId] > $score[$awayTeamId] ? $homeTeamId : $awayTeamId;
            }
        }

        return [
            'finished' => $decidedAt !== null,
            'home' => $score[$homeTeamId],
            'away' => $score[$awayTeamId],
            'winner' => $winner,
            'decided_at' => $decidedAt,
        ];
    }

    public function outOfTurn(array $kicks): ?int
    {
        $previous = null;

        foreach (array_values($kicks) as $index => $kick) {
            $teamId = (int) $kick['team_id'];

            if ($previous !== null && $teamId === $previous) {
                return $index + 1;
            }

            $previous = $teamId;
        }

        return null;
    }

    private function isDecided(array $score, array $taken, int $homeTeamId, int $awayTeamId): bool
    {
        $home = $score[$homeTeamId];
        $away = $score[$awayTeamId];

        if ($taken[$homeTeamId] <= self::REGULATION_KICKS && $taken[$awayTeamId] <= self::REGULATION_KICKS) {
            return $home + self::REGULATION_KICKS - $taken[$homeTeamId] < $away
                || $away + self::REGULATION_KICKS - $taken[$awayTeamId] < $home;
        }

        return $taken[$homeTeamId] === $taken[$awayTeamId] && $home !== $away;
    }
}

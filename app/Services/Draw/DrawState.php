<?php

namespace App\Services\Draw;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

final class DrawState
{
    public readonly array $teamsByGroup;

    public readonly bool $hasUnassignedTeams;

    public readonly bool $hasEqualGroups;

    public readonly ?int $groupSize;

    public readonly ?int $roundsPerTurn;

    public function __construct(
        public readonly League $league,
        public readonly Collection $teams,
        public readonly Collection $games,
    ) {
        $groups = array_fill_keys(range(1, max(1, (int) $league->groups_count)), []);
        $unassigned = false;

        foreach ($teams as $team) {
            $groupNumber = $team->pivot->group_number === null ? null : (int) $team->pivot->group_number;

            if ($groupNumber === null || ! array_key_exists($groupNumber, $groups)) {
                $unassigned = true;

                continue;
            }

            $groups[$groupNumber][] = $team;
        }

        $sizes = array_values(array_unique(array_map('count', $groups)));

        $this->teamsByGroup = $groups;
        $this->hasUnassignedTeams = $unassigned;
        $this->hasEqualGroups = count($sizes) === 1;
        $this->groupSize = ! $unassigned && $this->hasEqualGroups && $teams->isNotEmpty() ? $sizes[0] : null;
        $this->roundsPerTurn = $this->groupSize !== null && $this->groupSize >= 2
            ? RoundRobinScheduler::roundsPerTurn($this->groupSize)
            : null;
    }

    public function groupNumbers(): array
    {
        return array_keys($this->teamsByGroup);
    }

    public function teamIds(int $groupNumber): array
    {
        return array_map(fn (Team $team) => (int) $team->id, $this->teamsByGroup[$groupNumber] ?? []);
    }

    public function team(int $teamId): ?Team
    {
        return $this->teams->firstWhere('id', $teamId);
    }

    public function groupGames(int $groupNumber): Collection
    {
        return $this->games->where('group_number', $groupNumber)->values();
    }

    public function totalRounds(): ?int
    {
        return $this->roundsPerTurn === null ? null : $this->roundsPerTurn * (int) $this->league->group_legs;
    }

    public function drawnRounds(): int
    {
        return (int) $this->games->max('round');
    }

    public function nextRound(): ?int
    {
        $totalRounds = $this->totalRounds();
        $drawnRounds = $this->drawnRounds();

        return $totalRounds !== null && $drawnRounds >= $totalRounds ? null : $drawnRounds + 1;
    }

    public function turnOf(int $round): int
    {
        return intdiv($round - 1, $this->roundsPerTurn) + 1;
    }

    public function roundsOf(Collection $games): array
    {
        return $games
            ->sortBy('round')
            ->groupBy('round')
            ->map(fn ($roundGames) => $roundGames
                ->map(fn (Game $game) => [(int) $game->home_team_id, (int) $game->away_team_id])
                ->values()
                ->all())
            ->values()
            ->all();
    }
}

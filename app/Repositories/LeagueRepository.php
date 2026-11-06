<?php

namespace App\Repositories;

use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class LeagueRepository
{
    public function index(?array $ids = null, array $with = []): Collection
    {
        $query = League::query()->with($with);
        if ($ids !== null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    public function store(array $data): League
    {
        return League::create($data);
    }

    public function show(string $id, array $with = []): ?League
    {
        return League::with($with)->find($id);
    }

    public function findForUpdate(string $id): ?League
    {
        return League::query()->lockForUpdate()->find($id);
    }

    public function update(League $league): bool
    {
        return $league->save();
    }

    public function destroy(League $league): ?bool
    {
        return $league->delete();
    }

    public function hasDrawnRounds(League $league): bool
    {
        return $league->games()->whereNotNull('group_number')->exists();
    }

    public function hasKnockoutGames(League $league): bool
    {
        return $league->games()->whereNotNull('phase')->exists();
    }

    public function withDrawnRoundsAmong(array $leagueIds, ?Team $exceptMember = null): Collection
    {
        return League::query()
            ->whereIn('id', $leagueIds)
            ->whereHas('games', fn ($q) => $q->whereNotNull('group_number'))
            ->when($exceptMember, fn ($q) => $q->whereDoesntHave(
                'teams',
                fn ($teams) => $teams->where('teams.id', $exceptMember->id),
            ))
            ->orderBy('id')
            ->get(['id', 'name']);
    }

    public function teamIds(League $league): array
    {
        return $league->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
    }

    public function groupTeams(League $league): Collection
    {
        return $league->teams()->orderBy('teams.name')->orderBy('teams.id')->get();
    }

    public function assignGroups(League $league, array $groupByTeam): void
    {
        foreach ($groupByTeam as $teamId => $groupNumber) {
            $league->teams()->updateExistingPivot($teamId, ['group_number' => $groupNumber]);
        }
    }

    public function clearGroups(League $league): int
    {
        return $league->teams()->newPivotQuery()->update(['group_number' => null]);
    }
}

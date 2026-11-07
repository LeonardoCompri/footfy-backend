<?php

namespace App\Repositories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class TeamRepository
{
    public function index(?array $leagueIds = null): Collection
    {
        $query = Team::query();
        if ($leagueIds !== null) {
            $query->whereHas('leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds));
        }

        return $query->get();
    }

    public function store(array $data): Team
    {
        return Team::create($data);
    }

    public function show(string $id, array $with = []): ?Team
    {
        return Team::with($with)->find($id);
    }

    public function showWithLeagues(string $id, array $with = [], ?array $leagueIds = null): ?Team
    {
        $with['leagues'] = fn ($q) => $leagueIds === null ? $q : $q->whereIn('leagues.id', $leagueIds);

        return Team::with($with)->find($id);
    }

    public function update(Team $team): bool
    {
        return $team->save();
    }

    public function destroy(Team $team): ?bool
    {
        return $team->delete();
    }

    public function syncLeagues(Team $team, array $leagueIds): Team
    {
        $team->leagues()->syncWithoutDetaching($leagueIds);

        return $team->load('leagues');
    }

    public function isInDrawnGroup(Team $team, ?string $leagueId = null): bool
    {
        $query = $team->leagues()
            ->wherePivotNotNull('group_number')
            ->whereHas('games', fn ($q) => $q->whereNotNull('group_number'));

        if ($leagueId !== null) {
            $query->where('leagues.id', $leagueId);
        }

        return $query->exists();
    }

    public function removeLeague(Team $team, string $leagueId): Team
    {
        $team->leagues()->detach($leagueId);

        return $team->load('leagues');
    }
}

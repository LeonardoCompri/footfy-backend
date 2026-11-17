<?php

namespace App\Repositories;

use App\Models\League;
use App\Models\Team;
use App\Repositories\Concerns\BuildsListings;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TeamRepository
{
    use BuildsListings;

    public function index(?array $leagueIds = null, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $query = Team::query();
        if ($leagueIds !== null) {
            $query->inLeagues($leagueIds);
        }

        $this->searchByName($query, $filters);
        $this->sortListing($query, $filters, $paginate);

        return $this->getOrPaginate($query, $filters, $paginate);
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

    public function findForUpdate(int|string $id): ?Team
    {
        return Team::query()->lockForUpdate()->find($id);
    }

    public function rosterCount(Team $team): int
    {
        return $team->players()->count();
    }

    public function strictestActiveLeague(Team $team): ?League
    {
        return $team->leagues()
            ->notEnded()
            ->orderBy('leagues.max_players_per_team')
            ->orderBy('leagues.id')
            ->first();
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

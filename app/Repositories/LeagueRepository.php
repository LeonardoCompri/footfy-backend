<?php

namespace App\Repositories;

use App\Models\League;
use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

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

    public function show(string $id, array $with = [], array $counts = []): ?League
    {
        return League::with($with)->withCount($counts)->find($id);
    }

    public function championshipIdOf(int $leagueId): ?int
    {
        return League::query()->whereKey($leagueId)->value('championship_id');
    }

    public function soleLeagueOfTeam(int|string|null $teamId): ?League
    {
        if ($teamId === null) {
            return null;
        }

        $leagues = League::query()
            ->whereIn('id', DB::table('league_team')->select('league_id')->where('team_id', $teamId))
            ->limit(2)
            ->get(['id', 'championship_id']);

        return $leagues->count() === 1 ? $leagues->first() : null;
    }

    public function idsRelatedToTeam(int $teamId): array
    {
        $playerIds = DB::table('players')->select('id')->where('team_id', $teamId);

        return DB::table('league_team')->select('league_id')->where('team_id', $teamId)
            ->union(DB::table('games')->select('league_id')->where('home_team_id', $teamId))
            ->union(DB::table('games')->select('league_id')->where('away_team_id', $teamId))
            ->union(DB::table('game_events')->select('league_id')->whereIn('player_id', $playerIds))
            ->union(DB::table('game_events')->select('league_id')->whereIn('related_player_id', $playerIds))
            ->pluck('league_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function idsWithPlayerEvents(int $playerId): array
    {
        return DB::table('game_events')->select('league_id')->where('player_id', $playerId)
            ->union(DB::table('game_events')->select('league_id')->where('related_player_id', $playerId))
            ->pluck('league_id')
            ->map(fn ($id) => (int) $id)
            ->all();
    }

    public function findForUpdate(string $id): ?League
    {
        return League::query()->lockForUpdate()->find($id);
    }

    public function loadCounts(League $league, array $relations): League
    {
        return $league->loadCount($relations);
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
        return $league->games()->whereNotNull('knockout_stage')->exists();
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

    public function strictestActiveAmongNotLinkedTo(array $leagueIds, Team $team): ?League
    {
        return League::query()
            ->whereIn('id', $leagueIds)
            ->notEnded()
            ->whereDoesntHave('teams', fn ($teams) => $teams->where('teams.id', $team->id))
            ->orderBy('max_players_per_team')
            ->orderBy('id')
            ->first();
    }

    public function teamIds(League $league): array
    {
        return $league->teams()->pluck('teams.id')->map(fn ($id) => (int) $id)->all();
    }

    public function groupAssignments(League $league): array
    {
        return $league->teams()->newPivotQuery()->orderBy('team_id')->pluck('group_number', 'team_id')->all();
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

    public function assignAllToSingleGroup(League $league): int
    {
        return $league->teams()->newPivotQuery()
            ->where(fn ($q) => $q->whereNull('group_number')->orWhere('group_number', '!=', 1))
            ->update(['group_number' => 1]);
    }

    public function clearGroups(League $league): int
    {
        return $league->teams()->newPivotQuery()->update(['group_number' => null]);
    }
}

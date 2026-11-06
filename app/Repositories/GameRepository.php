<?php

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class GameRepository
{
    public function index(string $leagueId, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $query = Game::query()->with(['homeTeam', 'awayTeam', 'stadium'])->where('league_id', $leagueId);

        $query = $this->applyFilters($query, $filters);
        $query = $this->orderedForListing($query);

        if ($paginate) {
            $page = $filters['page'] ?? 1;
            $per_page = $filters['per_page'] ?? 20;

            return $query->paginate(perPage: (int) $per_page, page: (int) $page);
        }

        return $query->get();
    }

    public function store(array $data): Game
    {
        return Game::create($data);
    }

    public function show(string $id, array $with = []): ?Game
    {
        return Game::with($with)->find($id);
    }

    public function update(Game $game): bool
    {
        return $game->save();
    }

    public function findForUpdate(int $id): ?Game
    {
        return Game::query()->lockForUpdate()->find($id);
    }

    public function scoringEventCounts(Game $game): Collection
    {
        return $game->events()
            ->joinEventType()
            ->whereNotNull('game_event_types.counts_for')
            ->groupBy('game_events.team_id', 'game_event_types.counts_for')
            ->selectRaw('game_events.team_id as team_id, game_event_types.counts_for as counts_for, COUNT(*) as total')
            ->get();
    }

    public function syncEventsLeague(Game $game): int
    {
        return $game->events()->update(['league_id' => $game->league_id]);
    }

    public function destroy(Game $game): ?bool
    {
        return $game->delete();
    }

    private function applyFilters(Builder $query, array $filters = []): Builder
    {
        $query->when(filled($filters['round'] ?? null), function (Builder $q) use ($filters) {
            $q->where('round', $filters['round']);
        });

        $query->when(filled($filters['phase'] ?? null), function (Builder $q) use ($filters) {
            $q->where('phase', $filters['phase']);
        });

        $query->when(filled($filters['status'] ?? null), function (Builder $q) use ($filters) {
            $q->where('status', $filters['status']);
        });

        $query->when(filled($filters['team_id'] ?? null), function (Builder $q) use ($filters) {
            $teamId = $filters['team_id'];
            $q->where(fn (Builder $sub) => $sub->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId));
        });

        return $query;
    }

    private function orderedForListing(Builder $query): Builder
    {
        return $query
            ->orderByRaw('round IS NULL, round')
            ->orderByRaw('phase_order IS NULL, phase_order')
            ->orderBy('match_date')
            ->orderBy('id');
    }
}

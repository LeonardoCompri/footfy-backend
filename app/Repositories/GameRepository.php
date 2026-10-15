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

        if($paginate) {
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

    public function destroy(Game $game): ?bool
    {
        return $game->delete();
    }

    private function applyFilters(Builder $query, array $filters = []): Builder
    {
        $query->when($filters['round'] ?? null, function (Builder $q, $round) {
            $q->where('round', $round);
        });

        $query->when($filters['phase'] ?? null, function (Builder $q, $phase) {
            $q->where('phase', $phase);
        });

        $query->when($filters['status'] ?? null, function (Builder $q, $status) {
            $q->where('status', $status);
        });

        $query->when($filters['team_id'] ?? null, function (Builder $q, $teamId) {
            $q->where(fn (Builder $sub) => $sub->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId));
        });

        return $query;
    }

    private function orderedForListing(Builder $query): Builder
    {
        return $query
            ->orderBy('round')
            ->orderBy('phase_order')
            ->orderBy('match_date')
            ->orderBy('id');
    }
}

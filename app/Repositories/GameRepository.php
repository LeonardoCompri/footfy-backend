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

    // Copia o league_id atual da partida para todos os seus eventos (campo denormalizado).
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
        // filled() em vez do valor cru: when() trata "0" como falso e ignoraria phase=0.
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
            ->orderBy('round')
            ->orderBy('phase_order')
            ->orderBy('match_date')
            ->orderBy('id');
    }
}

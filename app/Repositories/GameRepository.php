<?php

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Pagination\Paginator;

class GameRepository
{
    public function index(?array $leagueIds = null, array $filters = []): Collection
    {
        return $this->orderedForListing($this->filteredQuery($leagueIds, $filters))->get();
    }

    public function paginate(?array $leagueIds, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        $total = $this->filteredQuery($leagueIds, $filters)->toBase()->getCountForPagination();

        $items = new Collection();

        if ($total > ($page - 1) * $perPage) {
            $ids = $this->orderedForListing($this->filteredQuery($leagueIds, $filters))
                ->offset(($page - 1) * $perPage)
                ->limit($perPage)
                ->pluck('id');

            if ($ids->isNotEmpty()) {
                $items = $this->orderedForListing(Game::query()->whereIn('id', $ids))->get();
            }
        }

        return new LengthAwarePaginator($items, $total, $perPage, $page, [
            'path' => Paginator::resolveCurrentPath(),
            'pageName' => 'page',
        ]);
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

    private function filteredQuery(?array $leagueIds, array $filters): Builder
    {
        $query = Game::query();

        if ($leagueIds !== null) {
            $query->whereIn('league_id', $leagueIds);
        }

        foreach (['round', 'phase', 'status'] as $field) {
            if (isset($filters[$field]) && $filters[$field] !== '') {
                $query->where($field, $filters[$field]);
            }
        }

        if (! empty($filters['team_id'])) {
            $teamId = $filters['team_id'];
            $query->where(fn (Builder $q) => $q->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId));
        }

        return $query;
    }

    private function orderedForListing(Builder $query): Builder
    {
        return $query
            ->orderBy('league_id')
            ->orderBy('round')
            ->orderBy('phase_order')
            ->orderBy('match_date')
            ->orderBy('id');
    }
}

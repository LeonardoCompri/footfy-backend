<?php

namespace App\Repositories;

use App\Models\GameEvent;
use App\Repositories\Concerns\BuildsListings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

class GameEventRepository
{
    use BuildsListings;

    private const WITH = ['player', 'relatedPlayer'];

    public function index(array $filters = [], ?array $leagueIds = null): LengthAwarePaginator
    {
        $query = GameEvent::query()
            ->with(self::WITH)
            ->when($leagueIds !== null, fn (Builder $q) => $q->whereIn('league_id', $leagueIds));

        foreach (['league_id', 'game_id', 'team_id', 'type'] as $column) {
            $query->when(filled($filters[$column] ?? null), fn (Builder $q) => $q->where($column, $filters[$column]));
        }

        $query->when(filled($filters['player_id'] ?? null), fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('player_id', $filters['player_id'])
            ->orWhere('related_player_id', $filters['player_id'])));

        return $this->getOrPaginate($query->latestMinuteFirst(), $filters, paginate: true);
    }

    public function store(array $data): GameEvent
    {
        return GameEvent::create($data)->load(self::WITH);
    }

    public function show(string $id): ?GameEvent
    {
        return GameEvent::with(self::WITH)->find($id);
    }

    public function update(GameEvent $event): bool
    {
        $saved = $event->save();
        $event->load(self::WITH);

        return $saved;
    }

    public function destroy(GameEvent $event): ?bool
    {
        return $event->delete();
    }
}

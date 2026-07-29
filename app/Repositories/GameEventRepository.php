<?php

namespace App\Repositories;

use App\Models\GameEvent;
use Illuminate\Database\Eloquent\Collection;

class GameEventRepository
{
    public function index(?array $gameIds = null, ?string $type = null): Collection
    {
        $query = GameEvent::query();
        if ($gameIds !== null) {
            $query->whereIn('game_id', $gameIds);
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->get();
    }

    public function store(array $data): GameEvent
    {
        return GameEvent::create($data);
    }

    public function show(string $id): ?GameEvent
    {
        return GameEvent::find($id);
    }

    public function update(GameEvent $event): bool
    {
        return $event->save();
    }

    public function destroy(GameEvent $event): ?bool
    {
        return $event->delete();
    }
}

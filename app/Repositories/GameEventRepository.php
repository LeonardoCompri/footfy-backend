<?php

namespace App\Repositories;

use App\Models\GameEvent;
use Illuminate\Database\Eloquent\Collection;

class GameEventRepository
{
    private const WITH = ['player', 'relatedPlayer'];

    public function index(?array $gameIds = null, ?string $type = null): Collection
    {
        $query = GameEvent::query()->with(self::WITH);
        if ($gameIds !== null) {
            $query->whereIn('game_id', $gameIds);
        }
        if ($type !== null) {
            $query->where('type', $type);
        }

        return $query->latestMinuteFirst()->get();
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

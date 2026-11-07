<?php

namespace App\Repositories;

use App\Models\GameEventType;
use Illuminate\Database\Eloquent\Collection;

class GameEventTypeRepository
{
    public function index(): Collection
    {
        return GameEventType::query()->orderBy('sort_order')->orderBy('id')->get();
    }

    public function store(array $data): GameEventType
    {
        return GameEventType::create($data);
    }

    public function show(string $id): ?GameEventType
    {
        return GameEventType::find($id);
    }

    public function update(GameEventType $type): bool
    {
        return $type->save();
    }

    public function hasEvents(GameEventType $type): bool
    {
        return $type->events()->exists();
    }

    public function hasScoringType(array $slugs): bool
    {
        $slugs = array_values(array_filter($slugs, fn ($slug) => $slug !== null));

        return $slugs !== [] && GameEventType::query()
            ->whereIn('slug', $slugs)
            ->whereNotNull('counts_for')
            ->exists();
    }

    public function maxSortOrder(): int
    {
        return (int) GameEventType::query()->max('sort_order');
    }

    public function destroy(GameEventType $type): ?bool
    {
        return $type->delete();
    }
}

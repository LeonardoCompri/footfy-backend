<?php

namespace App\Repositories;

use App\Models\Championship;
use Illuminate\Database\Eloquent\Collection;

class ChampionshipRepository
{
    public function index(?array $ids = null, array $with = []): Collection
    {
        $query = Championship::query()->with($with);
        if ($ids != null) {
            $query->whereIn('id', $ids);
        }

        return $query->get();
    }

    public function store(array $data): Championship
    {
        return Championship::create($data);
    }

    public function show(string $id, array $with = []): ?Championship
    {
        return Championship::with($with)->find($id);
    }

    public function loadLeagues(Championship $championship): Championship
    {
        return $championship->load('leagues');
    }

    public function update(Championship $championship): bool
    {
        return $championship->save();
    }

    public function destroy(Championship $championship): ?bool
    {
        return $championship->delete();
    }
}

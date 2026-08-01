<?php

namespace App\Repositories;

use App\Models\League;
use Illuminate\Database\Eloquent\Collection;

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

    public function show(string $id, array $with = []): ?League
    {
        return League::with($with)->find($id);
    }

    public function update(League $league): bool
    {
        return $league->save();
    }

    public function destroy(League $league): ?bool
    {
        return $league->delete();
    }
}

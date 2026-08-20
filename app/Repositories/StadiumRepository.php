<?php

namespace App\Repositories;

use App\Models\Stadium;
use Illuminate\Database\Eloquent\Collection;

class StadiumRepository
{
    public function index(?array $leagueIds = null): Collection
    {
        $query = Stadium::query();
        if ($leagueIds !== null) {
            $query->whereIn('league_id', $leagueIds);
        }

        return $query->get();
    }

    public function store(array $data): Stadium
    {
        return Stadium::create($data);
    }

    public function show(string $id): ?Stadium
    {
        return Stadium::find($id);
    }

    public function update(Stadium $stadium): bool
    {
        return $stadium->save();
    }

    public function destroy(Stadium $stadium): ?bool
    {
        return $stadium->delete();
    }
}

<?php

namespace App\Repositories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class TeamRepository
{
    public function index(?array $championshipIds = null): Collection
    {
        $query = Team::query();
        if ($championshipIds !== null) {
            $query->whereHas('championships', fn ($q) => $q->whereIn('championships.id', $championshipIds));
        }
        return $query->get();
    }

    public function store(array $data): Team
    {
        return Team::create($data);
    }

    public function show(string $id, array $with = []): ?Team
    {
        return Team::with($with)->find($id);
    }

    public function update(Team $team): bool
    {
        return $team->save();
    }

    public function destroy(Team $team): ?bool
    {
        return $team->delete();
    }
}

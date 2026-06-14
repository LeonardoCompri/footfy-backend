<?php

namespace App\Repositories;

use App\Models\Team;
use Illuminate\Database\Eloquent\Collection;

class TeamRepository
{
    public function index(): Collection
    {
        return Team::all();
    }

    public function store(array $data): Team
    {
        return Team::create($data);
    }

    public function show(string $id): ?Team
    {
        return Team::find($id);
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

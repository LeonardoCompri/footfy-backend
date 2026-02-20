<?php

namespace App\Repositories;

use App\Models\Championship;
use Illuminate\Database\Eloquent\Collection;

class ChampionshipRepository
{
    public function index(): Collection
    {
        return Championship::all();
    }

    public function store(array $data): Championship
    {
        return Championship::create($data);
    }

    public function show(string $id): ?Championship
    {
        return Championship::find($id);
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

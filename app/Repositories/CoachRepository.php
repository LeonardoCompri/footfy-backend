<?php

namespace App\Repositories;

use App\Models\Coach;
use Illuminate\Database\Eloquent\Collection;

class CoachRepository
{
    public function index(): Collection
    {
        return Coach::all();
    }

    public function store(array $data): Coach
    {
        return Coach::create($data);
    }

    public function show(string $id): ?Coach
    {
        return Coach::find($id);
    }

    public function update(Coach $coach): bool
    {
        return $coach->save();
    }

    public function destroy(Coach $coach): ?bool
    {
        return $coach->delete();
    }
}

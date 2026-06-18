<?php

namespace App\Repositories;

use App\Models\Goal;
use Illuminate\Database\Eloquent\Collection;

class GoalRepository
{
    public function index(?array $gameIds = null): Collection
    {
        $query = Goal::query();
        if ($gameIds !== null) {
            $query->whereIn('game_id', $gameIds);
        }
        return $query->get();
    }

    public function store(array $data): Goal
    {
        return Goal::create($data);
    }

    public function show(string $id): ?Goal
    {
        return Goal::find($id);
    }

    public function update(Goal $goal): bool
    {
        return $goal->save();
    }

    public function destroy(Goal $goal): ?bool
    {
        return $goal->delete();
    }
}

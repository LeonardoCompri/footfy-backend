<?php

namespace App\Repositories;

use App\Models\Coach;
use Illuminate\Database\Eloquent\Collection;

class CoachRepository
{
    public function index(?array $teamIds = null): Collection
    {
        $query = Coach::query();
        if ($teamIds !== null) {
            $query->whereIn('team_id', $teamIds);
        }
        return $query->get();
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

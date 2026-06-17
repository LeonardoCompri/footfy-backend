<?php

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

class GameRepository
{
    public function index(?array $championshipIds = null): Collection
    {
        $query = Game::query();
        if ($championshipIds !== null) {
            $query->whereIn('championship_id', $championshipIds);
        }
        return $query->get();
    }

    public function store(array $data): Game
    {
        return Game::create($data);
    }

    public function show(string $id): ?Game
    {
        return Game::find($id);
    }

    public function update(Game $game): bool
    {
        return $game->save();
    }

    public function destroy(Game $game): ?bool
    {
        return $game->delete();
    }
}

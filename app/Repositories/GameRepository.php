<?php

namespace App\Repositories;

use App\Models\Game;
use Illuminate\Database\Eloquent\Collection;

class GameRepository
{
    public function index(): Collection
    {
        return Game::all();
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

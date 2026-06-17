<?php

namespace App\Repositories;

use App\Models\Player;
use Illuminate\Database\Eloquent\Collection;

class PlayerRepository
{
    public function index(?array $teamIds = null): Collection
    {
        $query = Player::query();
        if ($teamIds !== null) {
            $query->whereIn('team_id', $teamIds);
        }
        return $query->get();
    }

    public function store(array $data): Player
    {
        return Player::create($data);
    }

    public function show(string $id): ?Player
    {
        return Player::find($id);
    }

    public function update(Player $player): bool
    {
        return $player->save();
    }

    public function destroy(Player $player): ?bool
    {
        return $player->delete();
    }
}

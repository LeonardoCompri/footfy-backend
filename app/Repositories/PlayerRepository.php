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

    public function findTrashedByDocument(string $document): ?Player
    {
        return Player::onlyTrashed()->where('document', $document)->first();
    }

    public function restore(Player $player, array $data): Player
    {
        $player->fill($data);
        $player->setAttribute($player->getDeletedAtColumn(), null);
        $player->save();

        return $player;
    }

    public function show(string $id): ?Player
    {
        return Player::find($id);
    }

    public function findByUserIdWithTrashed(int $userId): ?Player
    {
        return Player::withTrashed()->where('user_id', $userId)->first();
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

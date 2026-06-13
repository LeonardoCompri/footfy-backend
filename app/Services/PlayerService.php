<?php

namespace App\Services;

use App\Models\Player;
use App\Repositories\PlayerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    public function __construct(private readonly PlayerRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): Player
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Player
    {
        $player = $this->repository->show($id);

        if (!$player) {
            throw ValidationException::withMessages(["message" => "Jogador não encontrado!"]);
        }

        return $player;
    }

    public function update(array $data, string $id): Player
    {
        $player = $this->show($id);
        $player->fill($data);

        $this->repository->update($player);

        return $player;
    }

    public function destroy(string $id): ?bool
    {
        $player = $this->show($id);

        return $this->repository->destroy($player);
    }
}

<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Repositories\PlayerRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    public function __construct(private readonly PlayerRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }
        $championshipIds = $user->championshipIds();
        $teamIds = Team::whereHas('championships', fn ($q) => $q->whereIn('championships.id', $championshipIds))
            ->pluck('id')
            ->toArray();
        return $this->repository->index($teamIds);
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

<?php

namespace App\Services;

use App\Models\Championship;
use App\Repositories\ChampionshipRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class ChampionshipService
{
    public function __construct(private readonly ChampionshipRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }
        return $this->repository->index($user->championshipIds());
    }

    public function store(array $data): Championship
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Championship
    {
        $championship = $this->repository->show($id);

        if (!$championship) {
            throw ValidationException::withMessages(["message" => "Campeonato não encontrado!"]);
        }

        return $championship;
    }

    public function update(array $data, string $id): Championship
    {
        $championship = $this->show($id);
        $championship->fill($data);

        $this->repository->update($championship);

        return $championship;
    }

    public function destroy(string $id): ?bool
    {
        $championship = $this->show($id);

        return $this->repository->destroy($championship);
    }
}

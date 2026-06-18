<?php

namespace App\Services;

use App\Models\Game;
use App\Models\Goal;
use App\Repositories\GoalRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GoalService
{
    public function __construct(private readonly GoalRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }
        $championshipIds = $user->championshipIds();
        $gameIds = Game::whereIn('championship_id', $championshipIds)
            ->pluck('id')
            ->toArray();
        return $this->repository->index($gameIds);
    }

    public function store(array $data): Goal
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Goal
    {
        $goal = $this->repository->show($id);

        if (!$goal) {
            throw ValidationException::withMessages(["message" => "Gol não encontrado!"]);
        }

        return $goal;
    }

    public function update(array $data, string $id): Goal
    {
        $goal = $this->show($id);
        $goal->fill($data);

        $this->repository->update($goal);

        return $goal;
    }

    public function destroy(string $id): ?bool
    {
        $goal = $this->show($id);

        return $this->repository->destroy($goal);
    }
}

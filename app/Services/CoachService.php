<?php

namespace App\Services;

use App\Models\Coach;
use App\Models\Team;
use App\Repositories\CoachRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class CoachService
{
    public function __construct(private readonly CoachRepository $repository) {}

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

    public function store(array $data): Coach
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Coach
    {
        $coach = $this->repository->show($id);

        if (!$coach) {
            throw ValidationException::withMessages(["message" => "Técnico não encontrado!"]);
        }

        return $coach;
    }

    public function update(array $data, string $id): Coach
    {
        $coach = $this->show($id);
        $coach->fill($data);

        $this->repository->update($coach);

        return $coach;
    }

    public function destroy(string $id): ?bool
    {
        $coach = $this->show($id);

        return $this->repository->destroy($coach);
    }
}

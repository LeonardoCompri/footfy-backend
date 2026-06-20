<?php

namespace App\Services;

use App\Models\League;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class LeagueService
{
    public function __construct(private readonly LeagueRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }

        return $this->repository->index($user->leagueIds());
    }

    public function publicIndex(): Collection
    {
        return $this->repository->index(null);
    }

    public function store(array $data): League
    {
        return $this->repository->store($data);
    }

    public function show(string $id): League
    {
        $league = $this->repository->show($id);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return $league;
    }

    public function publicShow(string $id): League
    {
        $league = $this->repository->show($id, ['teams', 'games']);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return $league;
    }

    public function update(array $data, string $id): League
    {
        $league = $this->show($id);
        $league->fill($data);

        $this->repository->update($league);

        return $league;
    }

    public function destroy(string $id): ?bool
    {
        $league = $this->show($id);

        return $this->repository->destroy($league);
    }
}

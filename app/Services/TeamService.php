<?php

namespace App\Services;

use App\Models\Team;
use App\Repositories\TeamRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public function __construct(private readonly TeamRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): Team
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Team
    {
        $team = $this->repository->show($id);

        if (!$team) {
            throw ValidationException::withMessages(["message" => "Time não encontrado!"]);
        }

        return $team;
    }

    public function update(array $data, string $id): Team
    {
        $team = $this->show($id);
        $team->fill($data);

        $this->repository->update($team);

        return $team;
    }

    public function destroy(string $id): ?bool
    {
        $team = $this->show($id);

        return $this->repository->destroy($team);
    }
}

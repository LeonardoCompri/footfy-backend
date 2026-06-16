<?php

namespace App\Services;

use App\Models\Coach;
use App\Repositories\CoachRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class CoachService
{
    public function __construct(private readonly CoachRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
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

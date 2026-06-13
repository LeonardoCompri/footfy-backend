<?php

namespace App\Services;

use App\Models\Stadium;
use App\Repositories\StadiumRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class StadiumService
{
    public function __construct(private readonly StadiumRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): Stadium
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Stadium
    {
        $stadium = $this->repository->show($id);

        if (!$stadium) {
            throw ValidationException::withMessages(["message" => "Estádio não encontrado!"]);
        }

        return $stadium;
    }

    public function update(array $data, string $id): Stadium
    {
        $stadium = $this->show($id);
        $stadium->fill($data);

        $this->repository->update($stadium);

        return $stadium;
    }

    public function destroy(string $id): ?bool
    {
        $stadium = $this->show($id);

        return $this->repository->destroy($stadium);
    }
}

<?php

namespace App\Services;

use App\Models\Championship;
use App\Repositories\ChampionshipRepository;
use Illuminate\Database\Eloquent\Collection;

class ChampionshipService
{
    public function __construct(private readonly ChampionshipRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): Championship
    {
        return $this->repository->store($data);
    }

    public function show(string $id)
    {
        //
    }

    public function update(array $data, string $id)
    {
        //
    }

    public function destroy(string $id)
    {
        //
    }
}

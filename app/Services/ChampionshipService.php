<?php

namespace App\Services;

use App\Models\Championship;
use App\Repositories\ChampionshipRepository;
use App\Services\Concerns\HandlesImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class ChampionshipService
{
    use HandlesImageUpload;

    public function __construct(private readonly ChampionshipRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function publicIndex(): Collection
    {
        return $this->repository->index(null, ['leagues']);
    }

    public function store(array $data): Championship
    {
        if (isset($data['image'])) {
            $data['image'] = $this->storeImage($data['image'], 'championships');
        }

        return $this->repository->store($data);
    }

    public function show(string $id): Championship
    {
        $championship = $this->repository->show($id);

        if (! $championship) {
            throw ValidationException::withMessages(['message' => 'Campeonato não encontrado!']);
        }

        return $championship;
    }

    public function publicShow(string $id): Championship
    {
        $championship = $this->repository->show($id, ['leagues']);

        if (!$championship) {
            throw ValidationException::withMessages(["message" => "Campeonato não encontrado!"]);
        }

        return $championship;
    }

    public function update(array $data, string $id): Championship
    {
        $championship = $this->show($id);

        if (isset($data['image'])) {
            $this->deleteImage($championship->image);
            $data['image'] = $this->storeImage($data['image'], 'championships');
        }

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

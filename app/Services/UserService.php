<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Validation\ValidationException;

class UserService
{
    public function __construct(private readonly UserRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): User
    {
        $role = $data['role'];
        unset($data['role']);
        return $this->repository->store($data, $role);
    }

    public function show(string $id): User
    {
        $user = $this->repository->show($id);

        if (!$user) {
            throw ValidationException::withMessages(['message' => 'Usuário não encontrado!']);
        }

        return $user;
    }

    public function update(array $data, string $id): User
    {
        $user = $this->show($id);
        return $this->repository->update($user, $data);
    }

    public function destroy(string $id): ?bool
    {
        $user = $this->show($id);
        return $this->repository->destroy($user);
    }

    public function syncChampionships(string $id, array $championshipIds): User
    {
        $user = $this->show($id);
        return $this->repository->syncChampionships($user, $championshipIds);
    }

    public function removeChampionship(string $id, string $championshipId): User
    {
        $user = $this->show($id);
        return $this->repository->removeChampionship($user, $championshipId);
    }
}

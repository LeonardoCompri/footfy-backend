<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\UserRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
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

        if (! $user) {
            throw ValidationException::withMessages(['message' => 'Usuário não encontrado!']);
        }

        return $user;
    }

    // Usuários não-master (ex.: admin, que agora também acessa esta rota) não podem
    // alterar o papel de ninguém por aqui — o campo `role` é ignorado nesse caso.
    public function update(array $data, string $id): User
    {
        $user = $this->show($id);

        if (isset($data['role']) && ! Auth::user()->isMaster()) {
            unset($data['role']);
        }

        return $this->repository->update($user, $data);
    }

    public function destroy(string $id): ?bool
    {
        $user = $this->show($id);

        return $this->repository->destroy($user);
    }

    public function syncLeagues(string $id, array $leagueIds): User
    {
        $user = $this->show($id);

        return $this->repository->syncLeagues($user, $leagueIds);
    }

    public function removeLeague(string $id, string $leagueId): User
    {
        $user = $this->show($id);

        return $this->repository->removeLeague($user, $leagueId);
    }
}

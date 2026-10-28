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

    // Master vê todos os usuários (opcionalmente só os de uma liga). Demais papéis veem
    // apenas usuários não-master vinculados às suas ligas, sempre incluindo a si mesmos;
    // um leagueId fora das ligas do usuário é rejeitado.
    public function index(?string $leagueId = null): Collection
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return $this->repository->index($leagueId !== null ? [$leagueId] : null);
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId !== null) {
            if (! in_array((int) $leagueId, $leagueIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
            }

            $leagueIds = [$leagueId];
        }

        return $this->repository->index($leagueIds, $user->id, true);
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

<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    // leagueIds: restringe a usuários vinculados a ao menos uma dessas ligas.
    // excludeMasters: remove usuários com papel master do resultado filtrado.
    // includeUserId: usuário sempre incluído, independentemente dos filtros acima.
    public function index(?array $leagueIds = null, ?int $includeUserId = null, bool $excludeMasters = false): Collection
    {
        $query = User::with(['leagues', 'roles']);

        $query->where(function ($q) use ($leagueIds, $includeUserId, $excludeMasters) {
            $q->where(function ($filtered) use ($leagueIds, $excludeMasters) {
                if ($leagueIds !== null) {
                    $filtered->whereHas('leagues', fn ($l) => $l->whereIn('leagues.id', $leagueIds));
                }
                if ($excludeMasters) {
                    $filtered->whereDoesntHave('roles', fn ($r) => $r->where('name', 'master'));
                }
            });

            if ($includeUserId !== null) {
                $q->orWhere('users.id', $includeUserId);
            }
        });

        return $query->get();
    }

    public function store(array $data, string $role): User
    {
        $user = User::create($data);
        $user->assignRole($role);

        return $user->load('leagues');
    }

    public function show(string $id): ?User
    {
        return User::with('leagues')->find($id);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }
        unset($data['role']);
        $user->fill($data);
        $user->save();

        return $user->load('leagues');
    }

    public function destroy(User $user): ?bool
    {
        return $user->delete();
    }

    public function syncLeagues(User $user, array $leagueIds): User
    {
        $user->leagues()->syncWithoutDetaching($leagueIds);

        return $user->load('leagues');
    }

    public function removeLeague(User $user, string $leagueId): User
    {
        $user->leagues()->detach($leagueId);

        return $user->load('leagues');
    }
}

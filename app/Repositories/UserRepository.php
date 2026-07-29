<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function index(): Collection
    {
        return User::with('leagues')->get();
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

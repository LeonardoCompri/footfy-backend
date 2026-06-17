<?php

namespace App\Repositories;

use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    public function index(): Collection
    {
        return User::with('championships')->get();
    }

    public function store(array $data, string $role): User
    {
        $user = User::create($data);
        $user->assignRole($role);
        return $user->load('championships');
    }

    public function show(string $id): ?User
    {
        return User::with('championships')->find($id);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);
        }
        unset($data['role']);
        $user->fill($data);
        $user->save();
        return $user->load('championships');
    }

    public function destroy(User $user): ?bool
    {
        return $user->delete();
    }

    public function syncChampionships(User $user, array $championshipIds): User
    {
        $user->championships()->syncWithoutDetaching($championshipIds);
        return $user->load('championships');
    }

    public function removeChampionship(User $user, string $championshipId): User
    {
        $user->championships()->detach($championshipId);
        return $user->load('championships');
    }
}

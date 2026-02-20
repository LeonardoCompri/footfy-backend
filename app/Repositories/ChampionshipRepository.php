<?php

namespace App\Repositories;

use App\Models\Championship;
use Illuminate\Database\Eloquent\Collection;

class ChampionshipRepository
{
    public function index(): Collection
    {
        return Championship::all();
    }

    public function store(array $data)
    {
        return Championship::create($data);
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

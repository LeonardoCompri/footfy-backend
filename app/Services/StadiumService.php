<?php

namespace App\Services;

use App\Models\Stadium;
use App\Repositories\StadiumRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class StadiumService
{
    public function __construct(private readonly StadiumRepository $repository) {}

    // Lista estádios do usuário, opcionalmente restrita a uma única liga (leagueId),
    // sempre validando que essa liga está entre as que o usuário tem acesso.
    public function index(?string $leagueId = null): Collection
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return $this->repository->index($leagueId ? [$leagueId] : null);
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId !== null) {
            if (! in_array((int) $leagueId, $leagueIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
            }

            $leagueIds = [$leagueId];
        }

        return $this->repository->index($leagueIds);
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

    // games.stadium_id é nullOnDelete, então o banco não barra a exclusão: a checagem de
    // partidas vinculadas precisa ser explícita para não apagar o estádio do histórico.
    public function destroy(string $id): ?bool
    {
        $stadium = $this->show($id);

        if ($this->repository->hasGames($stadium)) {
            throw ValidationException::withMessages(['message' => 'Não é possível remover o estádio pois existem partidas vinculadas a ele.']);
        }

        return $this->repository->destroy($stadium);
    }
}

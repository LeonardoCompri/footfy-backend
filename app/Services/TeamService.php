<?php

namespace App\Services;

use App\Models\Team;
use App\Repositories\TeamRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TeamService
{
    public function __construct(private readonly TeamRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }

        return $this->repository->index($user->leagueIds());
    }

    public function store(array $data): Team
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Team
    {
        $team = $this->repository->show($id);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
        }

        return $team;
    }

    // Busca time por id já com os jogadores carregados, para consumo público (guest).
    public function publicShow(string $id): Team
    {
        $team = $this->repository->show($id, ['players']);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
        }

        return $team;
    }

    // Busca time por id já com os jogadores carregados, para consumo público (guest).
    public function publicShow(string $id): Team
    {
        $team = $this->repository->show($id, ['players']);

        if (!$team) {
            throw ValidationException::withMessages(["message" => "Time não encontrado!"]);
        }

        return $team;
    }

    public function update(array $data, string $id): Team
    {
        $team = $this->show($id);
        $team->fill($data);

        $this->repository->update($team);

        return $team;
    }

    public function destroy(string $id): ?bool
    {
        $team = $this->show($id);

        try {
            return $this->repository->destroy($team);
        } catch (QueryException $ex) {
            throw ValidationException::withMessages(['message' => 'Não é possível remover o time pois existem partidas vinculadas a ele.']);
        }
    }
}

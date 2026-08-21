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

    // Lista times do usuário, opcionalmente restrita a uma única liga (leagueId),
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

    public function store(array $data): Team
    {
        return $this->repository->store($data);
    }

    // Busca time por id já com jogadores e comissão técnica, evitando N+1 no TeamResource.
    public function show(string $id): Team
    {
        $team = $this->repository->show($id, ['players', 'technicalCommitteeMembers']);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
        }

        return $team;
    }

    // Busca time por id já com jogadores e comissão técnica, para consumo público (guest).
    public function publicShow(string $id): Team
    {
        $team = $this->repository->show($id, ['players', 'technicalCommitteeMembers']);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
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

    /**
     * Vincula o time às ligas informadas (pivot league_team). Para usuários não-master,
     * cada league_id precisa estar entre as ligas que o usuário tem acesso — diferente do
     * middleware `access:team`, aqui não se exige acesso prévio ao time (ele pode ainda não
     * estar vinculado a nenhuma liga), apenas às ligas de destino do vínculo.
     */
    public function syncLeagues(string $id, array $leagueIds): Team
    {
        $team = $this->findOrFail($id);
        $this->ensureUserHasAccessToLeagues($leagueIds);

        return $this->repository->syncLeagues($team, $leagueIds);
    }

    public function removeLeague(string $id, string $leagueId): Team
    {
        $team = $this->findOrFail($id);
        $user = Auth::user();

        if (! $user->isMaster() && ! in_array((int) $leagueId, $user->leagueIds())) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
        }

        return $this->repository->removeLeague($team, $leagueId);
    }

    private function findOrFail(string $id): Team
    {
        $team = $this->repository->show($id);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
        }

        return $team;
    }

    private function ensureUserHasAccessToLeagues(array $leagueIds): void
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return;
        }

        $allowedLeagueIds = $user->leagueIds();
        $unauthorizedLeagueIds = array_diff(array_map('intval', $leagueIds), $allowedLeagueIds);

        if (! empty($unauthorizedLeagueIds)) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a uma ou mais ligas informadas.']);
        }
    }
}

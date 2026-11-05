<?php

namespace App\Services;

use App\Models\Team;
use App\Repositories\TeamRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use App\Services\Concerns\HandlesImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class TeamService
{
    use EnsuresLeagueAccess, HandlesImageUpload;

    public function __construct(private readonly TeamRepository $repository) {}

    // Lista times do usuário, opcionalmente restrita a uma única liga (leagueId),
    // sempre validando que essa liga está entre as que o usuário tem acesso.
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

        return $this->repository->index($leagueIds);
    }

    /**
     * Cria o time e, se vierem league_ids, já o vincula às ligas na mesma transação.
     * O acesso às ligas é checado antes de gravar o logo; se a criação/vínculo falhar
     * depois do upload, o arquivo é removido para não ficar órfão no disco.
     */
    public function store(array $data): Team
    {
        $leagueIds = $data['league_ids'] ?? [];
        unset($data['league_ids']);

        if (! empty($leagueIds)) {
            $this->ensureUserHasAccessToLeagues($leagueIds);
        }

        if (isset($data['logo'])) {
            $data['logo'] = $this->storeImage($data['logo'], 'teams');
        }

        try {
            return DB::transaction(function () use ($data, $leagueIds) {
                $team = $this->repository->store($data);

                // Sempre carrega `leagues` (mesmo vazio) para a resposta do store.
                return $this->repository->syncLeagues($team, $leagueIds);
            });
        } catch (Throwable $th) {
            $this->deleteImage($data['logo'] ?? null);

            throw $th;
        }
    }

    /**
     * Busca time por id já com jogadores, comissão técnica e ligas, evitando N+1 no TeamResource.
     * Ligas: master vê todas; não-master vê só a liga de $leagueId (que precisa estar entre as
     * suas) ou, sem filtro, apenas as ligas do time às quais tem acesso.
     */
    public function show(string $id, ?string $leagueId = null): Team
    {
        $user = Auth::user();
        $visibleLeagueIds = null;

        if (! $user->isMaster()) {
            $visibleLeagueIds = $user->leagueIds();

            if ($leagueId !== null) {
                if (! in_array((int) $leagueId, $visibleLeagueIds)) {
                    throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
                }

                $visibleLeagueIds = [(int) $leagueId];
            }
        }

        $team = $this->repository->showWithLeagues($id, ['players', 'technicalCommitteeMembers'], $visibleLeagueIds);

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

        if (isset($data['logo'])) {
            $this->deleteImage($team->logo);
            $data['logo'] = $this->storeImage($data['logo'], 'teams');
        }

        $team->fill($data);

        $this->repository->update($team);

        return $team;
    }

    // O logo só é removido do disco depois que o registro foi apagado (time com partidas mantém o arquivo).
    public function destroy(string $id): ?bool
    {
        $team = $this->show($id);

        try {
            $deleted = $this->repository->destroy($team);
        } catch (QueryException $ex) {
            throw ValidationException::withMessages(['message' => 'Não é possível remover o time pois existem partidas vinculadas a ele.']);
        }

        if ($deleted) {
            $this->deleteImage($team->logo);
        }

        return $deleted;
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
}

<?php

namespace App\Services;

use App\Models\Team;
use App\Repositories\LeagueRepository;
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

    public function __construct(
        private readonly TeamRepository $repository,
        private readonly LeagueRepository $leagueRepository,
    ) {}

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

    public function store(array $data): Team
    {
        $leagueIds = $data['league_ids'] ?? [];
        unset($data['league_ids']);

        if (! empty($leagueIds)) {
            $this->ensureUserHasAccessToLeagues($leagueIds);
            $this->ensureLeaguesAcceptNewTeams($leagueIds);
        }

        if (isset($data['logo'])) {
            $data['logo'] = $this->storeImage($data['logo'], 'teams');
        }

        try {
            return DB::transaction(function () use ($data, $leagueIds) {
                $team = $this->repository->store($data);

                return $this->repository->syncLeagues($team, $leagueIds);
            });
        } catch (Throwable $th) {
            $this->deleteImage($data['logo'] ?? null);

            throw $th;
        }
    }

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
        $oldLogo = null;

        if (array_key_exists('logo', $data)) {
            $oldLogo = $team->logo;

            if ($data['logo'] !== null) {
                $data['logo'] = $this->storeImage($data['logo'], 'teams');
            }
        }

        $team->fill($data);

        try {
            $this->repository->update($team);
        } catch (Throwable $th) {
            $this->deleteImage($data['logo'] ?? null);

            throw $th;
        }

        $this->deleteImage($oldLogo);

        return $team;
    }

    public function destroy(string $id): ?bool
    {
        $team = $this->show($id);

        if ($this->repository->isInDrawnGroup($team)) {
            throw ValidationException::withMessages(['message' => 'Não é possível remover o time pois ele está no grupo de uma liga com rodadas sorteadas.']);
        }

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

    public function syncLeagues(string $id, array $leagueIds): Team
    {
        $team = $this->findOrFail($id);
        $this->ensureUserHasAccessToLeagues($leagueIds);
        $this->ensureLeaguesAcceptNewTeams($leagueIds, $team);

        return DB::transaction(function () use ($team, $leagueIds) {
            $this->repository->findForUpdate($team->id);
            $this->ensureRosterFitsNewLeagues($team, $leagueIds);

            return $this->repository->syncLeagues($team, $leagueIds);
        });
    }

    private function ensureRosterFitsNewLeagues(Team $team, array $leagueIds): void
    {
        $league = $this->leagueRepository->strictestActiveAmongNotLinkedTo($leagueIds, $team);

        if (! $league) {
            return;
        }

        $rosterCount = $this->repository->rosterCount($team);

        if ($rosterCount > $league->max_players_per_team) {
            throw ValidationException::withMessages([
                'message' => sprintf(
                    'O time %s tem %d atletas, acima do limite de %d por time da liga %s.',
                    $team->name,
                    $rosterCount,
                    $league->max_players_per_team,
                    $league->name,
                ),
            ]);
        }
    }

    private function ensureLeaguesAcceptNewTeams(array $leagueIds, ?Team $team = null): void
    {
        $league = $this->leagueRepository->withDrawnRoundsAmong($leagueIds, $team)->first();

        if ($league) {
            throw ValidationException::withMessages([
                'message' => sprintf('Não é possível adicionar o time à liga %s porque ela já tem rodadas sorteadas.', $league->name),
            ]);
        }
    }

    public function removeLeague(string $id, string $leagueId): Team
    {
        $team = $this->findOrFail($id);
        $user = Auth::user();

        if (! $user->isMaster() && ! in_array((int) $leagueId, $user->leagueIds())) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
        }

        if ($this->repository->isInDrawnGroup($team, $leagueId)) {
            throw ValidationException::withMessages(['message' => 'Não é possível remover o time da liga pois ele está em um grupo com rodadas sorteadas.']);
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

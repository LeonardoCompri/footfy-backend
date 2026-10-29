<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\PlayerRepository;
use App\Repositories\UserRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    use EnsuresLeagueAccess;

    public function __construct(
        private readonly UserRepository $repository,
        private readonly PlayerRepository $playerRepository,
    ) {}

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
        $role = Arr::pull($data, 'role');
        $playerId = Arr::pull($data, 'player_id');

        $this->ensureUserCanLink($data['team_id'] ?? null, $playerId);

        return DB::transaction(fn () => $this->repository->store($data, $role, $playerId));
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

        $this->ensureUserCanLink($data['team_id'] ?? null, $data['player_id'] ?? null);

        return DB::transaction(fn () => $this->repository->update($user, $data));
    }

    public function destroy(string $id): ?bool
    {
        $user = $this->show($id);

        return $this->repository->destroy($user);
    }

    // Coach e player não usam user_leagues: as ligas deles vêm do time vinculado.
    public function syncLeagues(string $id, array $leagueIds): User
    {
        $user = $this->show($id);

        if ($user->hasTeamScope()) {
            throw ValidationException::withMessages([
                'message' => 'As ligas de coach e player vêm do time vinculado; não é possível vincular ligas manualmente.',
            ]);
        }

        return $this->repository->syncLeagues($user, $leagueIds);
    }

    // Segue liberado para coach/player: serve para limpar vínculos antigos de user_leagues.
    public function removeLeague(string $id, string $leagueId): User
    {
        $user = $this->show($id);

        return $this->repository->removeLeague($user, $leagueId);
    }

    /**
     * O time do coach e o time do jogador vinculado ao player precisam estar nas ligas de
     * quem edita (master livre). Jogador sem time só pode ser vinculado por master.
     */
    private function ensureUserCanLink(int|string|null $teamId, int|string|null $playerId): void
    {
        if ($teamId !== null) {
            $this->ensureUserHasAccessToTeam($teamId);
        }

        if ($playerId !== null) {
            $this->ensureUserHasAccessToTeam($this->playerRepository->show($playerId)?->team_id);
        }
    }
}

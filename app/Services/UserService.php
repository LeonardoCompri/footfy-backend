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

    public function showProfile(): User
    {
        return $this->show((string) Auth::id());
    }

    public function updateProfile(array $data): User
    {
        return $this->repository->update($this->showProfile(), Arr::only($data, ['name', 'email', 'password']));
    }

    public function destroy(string $id): ?bool
    {
        $user = $this->show($id);

        return $this->repository->destroy($user);
    }

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

    public function removeLeague(string $id, string $leagueId): User
    {
        $user = $this->show($id);

        return $this->repository->removeLeague($user, $leagueId);
    }

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

<?php

namespace App\Services;

use App\Models\User;
use App\Repositories\LeagueRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\UserRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use App\Services\Concerns\SearchesByDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class UserService
{
    use EnsuresLeagueAccess, SearchesByDocument;

    public function __construct(
        private readonly UserRepository $repository,
        private readonly PlayerRepository $playerRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(?string $leagueId = null, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $user = Auth::user();
        $filters['document_search'] = $this->documentSearch($filters['search'] ?? null);

        if ($user->isMaster()) {
            return $this->repository->index($leagueId !== null ? [$leagueId] : null, filters: $filters, paginate: $paginate);
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId !== null) {
            if (! in_array((int) $leagueId, $leagueIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
            }

            $leagueIds = [$leagueId];
        }

        return $this->repository->index($leagueIds, $user->id, true, $filters, $paginate);
    }

    public function store(array $data): User
    {
        $role = Arr::pull($data, 'role');
        $playerId = Arr::pull($data, 'player_id');

        $this->ensureUserCanLink($data['team_id'] ?? null, $playerId);

        return DB::transaction(function () use ($data, $role, $playerId) {
            $user = $this->repository->store($data, $role, $playerId);
            $this->auditLogger->record('user.created', $user, new: $this->auditState($user));

            return $user;
        });
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

        return DB::transaction(fn () => $this->updateAndAudit($user, $data));
    }

    public function showProfile(): User
    {
        return $this->show((string) Auth::id());
    }

    public function updateProfile(array $data): User
    {
        $user = $this->showProfile();

        return DB::transaction(fn () => $this->updateAndAudit($user, Arr::only($data, ['name', 'email', 'password'])));
    }

    public function destroy(string $id): ?bool
    {
        $user = $this->show($id);
        $state = $this->auditState($user);

        return DB::transaction(function () use ($user, $state) {
            $deleted = $this->repository->destroy($user);

            if ($deleted) {
                $this->auditLogger->record('user.deleted', $user, old: $state);
            }

            return $deleted;
        });
    }

    public function syncLeagues(string $id, array $leagueIds): User
    {
        $user = $this->show($id);

        if ($user->hasTeamScope()) {
            throw ValidationException::withMessages([
                'message' => 'As ligas de coach e player vêm do time vinculado; não é possível vincular ligas manualmente.',
            ]);
        }

        return DB::transaction(function () use ($user, $leagueIds) {
            $attached = $this->repository->attachLeagues($user, $leagueIds);

            if ($attached !== []) {
                $this->recordLeagueLink('user.leagues_attached', $user, $attached);
            }

            return $this->repository->loadLeagues($user);
        });
    }

    public function removeLeague(string $id, string $leagueId): User
    {
        $user = $this->show($id);

        return DB::transaction(function () use ($user, $leagueId) {
            if ($this->repository->detachLeague($user, $leagueId) > 0) {
                $this->recordLeagueLink('user.leagues_detached', $user, [(int) $leagueId]);
            }

            return $this->repository->loadLeagues($user);
        });
    }

    private function updateAndAudit(User $user, array $data): User
    {
        $before = $this->auditState($user);
        $user = $this->repository->update($user, $data);
        $after = $this->auditState($user);

        $changed = array_keys(array_filter($after, fn ($value, $key) => $before[$key] !== $value, ARRAY_FILTER_USE_BOTH));
        $meta = array_key_exists('password', $data) ? ['password_changed' => true] : [];

        if ($changed !== [] || $meta !== []) {
            $this->auditLogger->record('user.updated', $user, Arr::only($before, $changed), Arr::only($after, $changed), $meta);
        }

        return $user;
    }

    private function auditState(User $user): array
    {
        return [
            'name' => $user->name,
            'email' => $user->email,
            'document' => $user->document,
            'team_id' => $user->team_id !== null ? (int) $user->team_id : null,
            'role' => $user->getRoleNames()->first(),
            'player_id' => $user->player !== null ? (int) $user->player->id : null,
            'league_ids' => $user->leagues->pluck('id')->map(fn ($id) => (int) $id)->sort()->values()->all(),
        ];
    }

    private function recordLeagueLink(string $action, User $user, array $leagueIds): void
    {
        $league = count($leagueIds) === 1 ? $this->leagueRepository->show((string) $leagueIds[0]) : null;

        $this->auditLogger->record($action, $user, meta: ['league_ids' => $leagueIds], league: $league);
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

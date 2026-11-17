<?php

namespace App\Repositories;

use App\Models\Player;
use App\Models\User;
use App\Repositories\Concerns\BuildsListings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class UserRepository
{
    use BuildsListings;

    private const WITH = ['leagues', 'team', 'player.team'];

    private const LISTING_COLUMNS = ['users.id', 'users.name', 'users.email', 'users.document', 'users.team_id', 'users.created_at', 'users.updated_at'];

    private const LISTING_WITH = [
        'roles:id,name',
        'leagues:id,name',
        'team:id,name,logo',
        'player:id,name,team_id,user_id',
        'player.team:id,name,logo',
    ];

    public function index(
        ?array $leagueIds = null,
        ?int $includeUserId = null,
        bool $excludeMasters = false,
        array $filters = [],
        bool $paginate = false,
    ): Collection|LengthAwarePaginator {
        $query = User::query()
            ->select(self::LISTING_COLUMNS)
            ->with(self::LISTING_WITH);

        $query->where(function ($q) use ($leagueIds, $includeUserId, $excludeMasters) {
            $q->where(function ($filtered) use ($leagueIds, $excludeMasters) {
                if ($leagueIds !== null) {
                    $filtered->inLeagues($leagueIds);
                }
                if ($excludeMasters) {
                    $filtered->whereNotIn('users.id', User::idsWithRoles(['master']));
                }
            });

            if ($includeUserId !== null) {
                $q->orWhere('users.id', $includeUserId);
            }
        });

        $query->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
            $q->where(function (Builder $sub) use ($filters) {
                $sub->where('users.name', 'like', '%'.$filters['search'].'%')
                    ->orWhere('users.email', 'like', '%'.$filters['search'].'%');

                if (filled($filters['document_search'] ?? null)) {
                    $sub->orWhere('users.document', 'like', '%'.$filters['document_search'].'%');
                }
            });
        });

        $this->sortListing($query, $filters, $paginate);

        return $this->getOrPaginate($query, $filters, $paginate);
    }

    public function store(array $data, string $role, int|string|null $playerId = null): User
    {
        $user = User::create($data);
        $user->assignRole($role);

        if ($playerId !== null) {
            $this->linkPlayer($user, $playerId);
        }

        return $user->load(self::WITH);
    }

    public function show(string $id): ?User
    {
        return User::with(self::WITH)->find($id);
    }

    public function update(User $user, array $data): User
    {
        if (isset($data['role'])) {
            $user->syncRoles([$data['role']]);

            if ($data['role'] !== 'coach') {
                $data['team_id'] = null;
            }
            if ($data['role'] !== 'player') {
                $this->linkPlayer($user, null);
            }
            if (in_array($data['role'], User::TEAM_SCOPED_ROLES, true)) {
                $user->leagues()->detach();
            }
        }

        if (array_key_exists('player_id', $data)) {
            $this->linkPlayer($user, $data['player_id']);
        }

        unset($data['role'], $data['player_id']);
        $user->fill($data);
        $user->save();

        return $user->load(self::WITH);
    }

    public function destroy(User $user): ?bool
    {
        return $user->delete();
    }

    public function syncLeagues(User $user, array $leagueIds): User
    {
        $user->leagues()->syncWithoutDetaching($leagueIds);

        return $user->load('leagues');
    }

    public function removeLeague(User $user, string $leagueId): User
    {
        $user->leagues()->detach($leagueId);

        return $user->load('leagues');
    }

    private function linkPlayer(User $user, int|string|null $playerId): void
    {
        Player::withTrashed()
            ->where('user_id', $user->id)
            ->when($playerId !== null, fn ($q) => $q->whereKeyNot($playerId))
            ->update(['user_id' => null]);

        if ($playerId !== null) {
            Player::whereKey($playerId)->update(['user_id' => $user->id]);
        }
    }
}

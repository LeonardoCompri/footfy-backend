<?php

namespace App\Repositories;

use App\Models\Player;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;

class UserRepository
{
    // Relações exibidas no UserResource (team do coach ou do jogador vinculado ao player).
    private const WITH = ['leagues', 'team', 'player.team'];

    // leagueIds: restringe a usuários vinculados a ao menos uma dessas ligas (User::scopeInLeagues).
    // excludeMasters: remove usuários com papel master do resultado filtrado.
    // includeUserId: usuário sempre incluído, independentemente dos filtros acima.
    public function index(?array $leagueIds = null, ?int $includeUserId = null, bool $excludeMasters = false): Collection
    {
        $query = User::with([...self::WITH, 'roles']);

        $query->where(function ($q) use ($leagueIds, $includeUserId, $excludeMasters) {
            $q->where(function ($filtered) use ($leagueIds, $excludeMasters) {
                if ($leagueIds !== null) {
                    $filtered->inLeagues($leagueIds);
                }
                if ($excludeMasters) {
                    $filtered->whereDoesntHave('roles', fn ($r) => $r->where('name', 'master'));
                }
            });

            if ($includeUserId !== null) {
                $q->orWhere('users.id', $includeUserId);
            }
        });

        return $query->get();
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

    /**
     * Trocar o papel limpa o vínculo que não vale para o novo papel: team_id fora de coach,
     * jogador fora de player e user_leagues ao virar coach/player (ligas vêm do time).
     * player_id no payload troca o jogador vinculado (null desvincula).
     */
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

    // Vincula o usuário a um único jogador (null desvincula). Solta o vínculo anterior antes,
    // inclusive de jogador removido (soft delete), por causa do unique em players.user_id.
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

<?php

namespace App\Repositories;

use App\Models\Player;
use App\Models\Team;
use App\Repositories\Concerns\BuildsListings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class PlayerRepository
{
    use BuildsListings;

    public function index(?array $leagueIds = null, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $query = Player::query();
        if ($leagueIds !== null) {
            $query->whereIn('team_id', Team::idsInLeagues($leagueIds));
        }

        $query->when(filled($filters['team_id'] ?? null), function (Builder $q) use ($filters) {
            $q->where('team_id', $filters['team_id']);
        });

        $query->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
            $q->where(function (Builder $sub) use ($filters) {
                $sub->where('name', 'like', '%'.$filters['search'].'%');

                if (filled($filters['document_search'] ?? null)) {
                    $sub->orWhere('document', 'like', '%'.$filters['document_search'].'%');
                }
            });
        });

        $this->sortListing($query, $filters, $paginate);

        return $this->getOrPaginate($query, $filters, $paginate);
    }

    public function store(array $data): Player
    {
        return Player::create($data);
    }

    public function findTrashedByDocument(string $document): ?Player
    {
        return Player::onlyTrashed()->where('document', $document)->first();
    }

    public function restore(Player $player, array $data): Player
    {
        $player->fill($data);
        $player->setAttribute($player->getDeletedAtColumn(), null);
        $player->save();

        return $player;
    }

    public function show(string $id): ?Player
    {
        return Player::find($id);
    }

    public function findByUserIdWithTrashed(int $userId): ?Player
    {
        return Player::withTrashed()->where('user_id', $userId)->first();
    }

    public function update(Player $player): bool
    {
        return $player->save();
    }

    public function destroy(Player $player): ?bool
    {
        return $player->delete();
    }
}

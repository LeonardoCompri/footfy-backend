<?php

namespace App\Repositories;

use App\Models\Team;
use App\Models\TechnicalCommitteeMember;
use App\Repositories\Concerns\BuildsListings;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

class TechnicalCommitteeMemberRepository
{
    use BuildsListings;

    public function index(?array $leagueIds = null, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $query = TechnicalCommitteeMember::query();
        if ($leagueIds !== null) {
            $query->whereIn('team_id', Team::idsInLeagues($leagueIds));
        }

        $query->when(filled($filters['team_id'] ?? null), function (Builder $q) use ($filters) {
            $q->where('team_id', $filters['team_id']);
        });

        $this->searchByName($query, $filters);
        $this->sortListing($query, $filters, $paginate);

        return $this->getOrPaginate($query, $filters, $paginate);
    }

    public function store(array $data): TechnicalCommitteeMember
    {
        return TechnicalCommitteeMember::create($data);
    }

    public function show(string $id): ?TechnicalCommitteeMember
    {
        return TechnicalCommitteeMember::find($id);
    }

    public function update(TechnicalCommitteeMember $technicalCommitteeMember): bool
    {
        return $technicalCommitteeMember->save();
    }

    public function destroy(TechnicalCommitteeMember $technicalCommitteeMember): ?bool
    {
        return $technicalCommitteeMember->delete();
    }
}

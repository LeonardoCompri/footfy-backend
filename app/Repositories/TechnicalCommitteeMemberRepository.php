<?php

namespace App\Repositories;

use App\Models\TechnicalCommitteeMember;
use Illuminate\Database\Eloquent\Collection;

class TechnicalCommitteeMemberRepository
{
    public function index(?array $teamIds = null): Collection
    {
        $query = TechnicalCommitteeMember::query();
        if ($teamIds !== null) {
            $query->whereIn('team_id', $teamIds);
        }

        return $query->get();
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

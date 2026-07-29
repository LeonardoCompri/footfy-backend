<?php

namespace App\Services;

use App\Models\TechnicalCommitteeMember;
use App\Models\Team;
use App\Repositories\TechnicalCommitteeMemberRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TechnicalCommitteeMemberService
{
    public function __construct(private readonly TechnicalCommitteeMemberRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }
        $leagueIds = $user->leagueIds();
        $teamIds = Team::whereHas('leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
            ->pluck('id')
            ->toArray();

        return $this->repository->index($teamIds);
    }

    public function store(array $data): TechnicalCommitteeMember
    {
        return $this->repository->store($data);
    }

    public function show(string $id): TechnicalCommitteeMember
    {
        $technicalCommitteeMember = $this->repository->show($id);

        if (! $technicalCommitteeMember) {
            throw ValidationException::withMessages(['message' => 'Membro da comissão técnica não encontrado!']);
        }

        return $technicalCommitteeMember;
    }

    public function update(array $data, string $id): TechnicalCommitteeMember
    {
        $technicalCommitteeMember = $this->show($id);
        $technicalCommitteeMember->fill($data);

        $this->repository->update($technicalCommitteeMember);

        return $technicalCommitteeMember;
    }

    public function destroy(string $id): ?bool
    {
        $technicalCommitteeMember = $this->show($id);

        return $this->repository->destroy($technicalCommitteeMember);
    }
}

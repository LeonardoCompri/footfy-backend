<?php

namespace App\Services;

use App\Models\TechnicalCommitteeMember;
use App\Models\Team;
use App\Repositories\TechnicalCommitteeMemberRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TechnicalCommitteeMemberService
{
    use EnsuresLeagueAccess;

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
        $this->ensureUserHasAccessToTeam($data['team_id']);

        return $this->repository->store($data);
    }

    // Lista de opções sugeridas para o select de função no admin (TechnicalCommitteeMember::ROLES).
    public function roles(): array
    {
        return TechnicalCommitteeMember::ROLES;
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

        if (array_key_exists('team_id', $data)) {
            $this->ensureUserHasAccessToTeam($data['team_id']);
        }

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

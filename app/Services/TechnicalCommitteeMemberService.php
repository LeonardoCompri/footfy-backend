<?php

namespace App\Services;

use App\Models\TechnicalCommitteeMember;
use App\Repositories\TechnicalCommitteeMemberRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class TechnicalCommitteeMemberService
{
    use EnsuresLeagueAccess;

    public function __construct(private readonly TechnicalCommitteeMemberRepository $repository) {}

    public function index(array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $user = Auth::user();

        if (filled($filters['team_id'] ?? null)) {
            $this->ensureUserHasAccessToTeam($filters['team_id'], 'Você não tem acesso a esse time.');
        }

        return $this->repository->index($user->isMaster() ? null : $user->leagueIds(), $filters, $paginate);
    }

    public function store(array $data): TechnicalCommitteeMember
    {
        $this->ensureUserHasAccessToTeam($data['team_id']);

        return $this->repository->store($data);
    }

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

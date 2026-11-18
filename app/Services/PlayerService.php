<?php

namespace App\Services;

use App\Models\Player;
use App\Repositories\LeagueRepository;
use App\Repositories\PlayerRepository;
use App\Repositories\TeamRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use App\Services\Concerns\SearchesByDocument;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    use EnsuresLeagueAccess, SearchesByDocument;

    public function __construct(
        private readonly PlayerRepository $repository,
        private readonly TeamRepository $teamRepository,
        private readonly LeagueRepository $leagueRepository,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(?string $leagueId = null, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        if (filled($filters['team_id'] ?? null)) {
            $this->ensureUserHasAccessToTeam($filters['team_id'], 'Você não tem acesso a esse time.');
        }

        $filters['document_search'] = $this->documentSearch($filters['search'] ?? null);

        return $this->repository->index($this->listingLeagueIds($leagueId), $filters, $paginate);
    }

    private function listingLeagueIds(?string $leagueId): ?array
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return $leagueId !== null ? [$leagueId] : null;
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId === null) {
            return $leagueIds;
        }

        if (! in_array((int) $leagueId, $leagueIds)) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
        }

        return [$leagueId];
    }

    public function store(array $data): Player
    {
        $this->ensureUserCanWriteToTeam($data['team_id']);

        return DB::transaction(function () use ($data) {
            $this->ensureTeamAcceptsNewPlayer($data['team_id']);

            $trashed = ! empty($data['document']) ? $this->repository->findTrashedByDocument($data['document']) : null;

            if ($trashed) {
                $trashed->fill(array_merge(['position' => null, 'number' => null, 'birth_date' => null], $data));
                $changes = $trashed->auditChanges();
                $player = $this->repository->restore($trashed);
                $this->audit('player.restored', $player, $changes);

                return $player;
            }

            return $this->repository->store($data);
        });
    }

    public function positions(): array
    {
        return Player::POSITIONS;
    }

    public function show(string $id): Player
    {
        $player = $this->repository->show($id);

        if (! $player) {
            throw ValidationException::withMessages(['message' => 'Jogador não encontrado!']);
        }

        return $player;
    }

    public function update(array $data, string $id): Player
    {
        $player = $this->show($id);
        $isTransfer = array_key_exists('team_id', $data) && (int) $data['team_id'] !== (int) $player->team_id;

        if (array_key_exists('team_id', $data)) {
            if (Auth::user()->isCoach() && (int) $data['team_id'] !== (int) $player->team_id) {
                throw ValidationException::withMessages(['message' => 'Você não pode transferir o jogador para outro time.']);
            }

            $this->ensureUserCanWriteToTeam($data['team_id']);
        }

        DB::transaction(function () use ($player, $data, $isTransfer) {
            if ($isTransfer) {
                $this->ensureTeamAcceptsNewPlayer($data['team_id']);
            }

            $player->fill($data);

            $this->updateAndAudit($player);
        });

        return $player;
    }

    public function updateOwnPlayer(array $data): Player
    {
        $player = $this->repository->findByUserIdWithTrashed(Auth::id());

        if (! $player) {
            throw new ModelNotFoundException('Nenhum jogador vinculado à sua conta.');
        }

        if ($player->trashed()) {
            throw ValidationException::withMessages(['message' => 'Você foi removido do elenco.']);
        }

        $player->fill($data);

        DB::transaction(fn () => $this->updateAndAudit($player));

        return $player;
    }

    public function destroy(string $id): ?bool
    {
        $player = $this->show($id);
        $snapshot = $player->auditAttributes();

        return DB::transaction(function () use ($player, $snapshot) {
            $deleted = $this->repository->destroy($player);

            if ($deleted) {
                $this->audit('player.deleted', $player, ['old' => $snapshot]);
            }

            return $deleted;
        });
    }

    private function updateAndAudit(Player $player): void
    {
        $changes = $player->auditChanges();
        $this->repository->update($player);

        if ($changes['new'] !== []) {
            $this->audit('player.updated', $player, $changes);
        }
    }

    private function audit(string $action, Player $player, array $changes): void
    {
        $teamId = $player->team_id !== null ? (int) $player->team_id : null;

        $this->auditLogger->record(
            $action,
            $player,
            $changes['old'] ?? [],
            $changes['new'] ?? [],
            ['team_id' => $teamId],
            $this->leagueRepository->soleLeagueOfTeam($teamId),
        );
    }

    private function ensureTeamAcceptsNewPlayer(int|string $teamId): void
    {
        $team = $this->teamRepository->findForUpdate($teamId);

        if (! $team) {
            throw ValidationException::withMessages(['message' => 'Time não encontrado!']);
        }

        $league = $this->teamRepository->strictestActiveLeague($team);

        if (! $league) {
            return;
        }

        $rosterCount = $this->teamRepository->rosterCount($team);

        if ($rosterCount >= $league->max_players_per_team) {
            throw ValidationException::withMessages([
                'message' => sprintf(
                    'O time %s já tem %d atletas e o limite da liga %s é de %d por time.',
                    $team->name,
                    $rosterCount,
                    $league->name,
                    $league->max_players_per_team,
                ),
            ]);
        }
    }

    private function ensureUserCanWriteToTeam(int|string $teamId): void
    {
        $user = Auth::user();

        if ($user->isCoach()) {
            if ($user->team_id === null || (int) $teamId !== (int) $user->team_id) {
                throw ValidationException::withMessages(['message' => 'Você só pode gerenciar jogadores do seu time.']);
            }

            return;
        }

        $this->ensureUserHasAccessToTeam($teamId);
    }
}

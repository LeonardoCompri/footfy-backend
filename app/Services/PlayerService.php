<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Repositories\PlayerRepository;
use App\Repositories\TeamRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    use EnsuresLeagueAccess;

    public function __construct(
        private readonly PlayerRepository $repository,
        private readonly TeamRepository $teamRepository,
    ) {}

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

    public function store(array $data): Player
    {
        $this->ensureUserCanWriteToTeam($data['team_id']);

        return DB::transaction(function () use ($data) {
            $this->ensureTeamAcceptsNewPlayer($data['team_id']);

            $trashed = ! empty($data['document']) ? $this->repository->findTrashedByDocument($data['document']) : null;

            if ($trashed) {
                return $this->repository->restore($trashed, array_merge(
                    ['position' => null, 'number' => null, 'birth_date' => null],
                    $data,
                ));
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

            $this->repository->update($player);
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

        $this->repository->update($player);

        return $player;
    }

    public function destroy(string $id): ?bool
    {
        $player = $this->show($id);

        return $this->repository->destroy($player);
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

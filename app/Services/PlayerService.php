<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Repositories\PlayerRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    use EnsuresLeagueAccess;

    public function __construct(private readonly PlayerRepository $repository) {}

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
        $this->ensureUserHasAccessToTeam($data['team_id']);

        return $this->repository->store($data);
    }

    // Lista de opções sugeridas para o select de posição no admin (Player::POSITIONS).
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

        if (array_key_exists('team_id', $data)) {
            $this->ensureUserHasAccessToTeam($data['team_id']);
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
}

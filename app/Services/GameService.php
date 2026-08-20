<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GameService
{
    public function __construct(private readonly GameRepository $repository) {}

    // Lista partidas do usuário, opcionalmente restrita a uma única liga (leagueId),
    // sempre validando que essa liga está entre as que o usuário tem acesso.
    public function index(?string $leagueId = null): Collection
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return $this->repository->index($leagueId ? [$leagueId] : null);
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId !== null) {
            if (! in_array((int) $leagueId, $leagueIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
            }

            $leagueIds = [$leagueId];
        }

        return $this->repository->index($leagueIds);
    }

    public function store(array $data): Game
    {
        return $this->repository->store($data);
    }

    // Carrega a partida já com homeTeam/awayTeam/stadium/events, para evitar
    // N+1 no GameResource ao exibir o detalhe de uma partida.
    public function show(string $id): Game
    {
        $game = $this->repository->show($id, ['homeTeam', 'awayTeam', 'stadium', 'events']);

        if (! $game) {
            throw ValidationException::withMessages(['message' => 'Partida não encontrada!']);
        }

        return $game;
    }

    public function update(array $data, string $id): Game
    {
        $game = $this->show($id);
        $game->fill($data);

        $this->repository->update($game);

        return $game;
    }

    public function destroy(string $id): ?bool
    {
        $game = $this->show($id);

        return $this->repository->destroy($game);
    }
}

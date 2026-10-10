<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GameService
{
    public function __construct(private readonly GameRepository $repository) {}

    public function index(?string $leagueId = null, array $filters = []): Collection
    {
        return $this->repository->index($this->resolveLeagueIds($leagueId), $filters);
    }

    public function paginate(?string $leagueId, array $filters, int $perPage, int $page): LengthAwarePaginator
    {
        return $this->repository->paginate($this->resolveLeagueIds($leagueId), $filters, $perPage, $page);
    }

    private function resolveLeagueIds(?string $leagueId): ?array
    {
        $user = Auth::user();

        if ($user->isMaster()) {
            return $leagueId ? [$leagueId] : null;
        }

        $leagueIds = $user->leagueIds();

        if ($leagueId !== null) {
            if (! in_array((int) $leagueId, $leagueIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
            }

            $leagueIds = [$leagueId];
        }

        return $leagueIds;
    }

    public function store(array $data): Game
    {
        return $this->repository->store($data);
    }

    public function show(string $id): Game
    {
        $game = $this->repository->show($id, ['homeTeam', 'awayTeam', 'stadium', 'events']);

        if (! $game) {
            throw ValidationException::withMessages(['message' => 'Partida não encontrada!']);
        }

        return $game;
    }

    public function publicShow(string $id): Game
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

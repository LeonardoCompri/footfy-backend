<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Repositories\GameEventRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameEventService
{
    use EnsuresLeagueAccess;

    public function __construct(
        private readonly GameEventRepository $repository,
        private readonly GameService $gameService,
        private readonly LeagueKnockoutService $knockoutService,
    ) {}

    public function index(?string $type = null, ?string $gameId = null): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index($gameId ? [$gameId] : null, $type);
        }
        $leagueIds = $user->leagueIds();
        $gameIds = Game::whereIn('league_id', $leagueIds)
            ->pluck('id')
            ->toArray();

        if ($gameId !== null) {
            if (! in_array((int) $gameId, $gameIds)) {
                throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa partida.']);
            }

            $gameIds = [$gameId];
        }

        return $this->repository->index($gameIds, $type);
    }

    public function store(array $data): GameEvent
    {
        $game = Game::find($data['game_id']);
        $this->ensureUserHasAccessToLeagues([$game->league_id]);
        $data['league_id'] = $game->league_id;
        $this->ensureGroupResultsEditable($game);

        return DB::transaction(function () use ($data, $game) {
            $event = $this->repository->store($data);
            $this->gameService->refreshScore($game->id);

            return $event;
        });
    }

    public function show(string $id): GameEvent
    {
        $event = $this->repository->show($id);

        if (! $event) {
            throw ValidationException::withMessages(['message' => 'Evento não encontrado!']);
        }

        return $event;
    }

    public function update(array $data, string $id): GameEvent
    {
        $event = $this->show($id);
        $game = null;

        if (isset($data['game_id'])) {
            $game = Game::find($data['game_id']);
            $this->ensureUserHasAccessToLeagues([$game->league_id]);
            $data['league_id'] = $game->league_id;
        }

        $previousGame = $event->game;
        $previousGameId = $event->game_id;
        $event->fill($data);

        if ($event->isDirty(['game_id', 'team_id', 'type'])) {
            $this->ensureGroupResultsEditable($previousGame);

            if ($event->isDirty('game_id')) {
                $this->ensureGroupResultsEditable($game);
            }
        }

        DB::transaction(function () use ($event, $previousGameId) {
            $this->repository->update($event);
            $this->refreshScores([$previousGameId, $event->game_id]);
        });

        return $event;
    }

    public function destroy(string $id): ?bool
    {
        $event = $this->show($id);
        $this->ensureGroupResultsEditable($event->game);

        return DB::transaction(function () use ($event) {
            $deleted = $this->repository->destroy($event);
            $this->gameService->refreshScore($event->game_id);

            return $deleted;
        });
    }

    private function ensureGroupResultsEditable(?Game $game): void
    {
        if ($game && $this->knockoutService->groupResultsLocked($game)) {
            throw ValidationException::withMessages(['message' => LeagueKnockoutService::GROUP_RESULTS_LOCKED]);
        }
    }

    private function refreshScores(array $gameIds): void
    {
        $gameIds = array_unique(array_map('intval', $gameIds));
        sort($gameIds);

        foreach ($gameIds as $gameId) {
            $this->gameService->refreshScore($gameId);
        }
    }
}

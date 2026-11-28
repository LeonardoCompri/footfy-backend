<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Repositories\GameEventRepository;
use App\Repositories\GameEventTypeRepository;
use App\Repositories\GameRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Pagination\LengthAwarePaginator;
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
        private readonly GameEventTypeRepository $eventTypeRepository,
        private readonly GameRepository $gameRepository,
        private readonly AuditLogger $auditLogger,
        private readonly LeagueCacheVersion $cacheVersion,
    ) {}

    public function index(array $filters = []): LengthAwarePaginator
    {
        $user = Auth::user();
        $leagueIds = $user->isMaster() ? null : $user->leagueIds();

        if (filled($filters['league_id'] ?? null)) {
            $this->ensureUserHasAccessToLeagues([$filters['league_id']]);
        }

        if (filled($filters['team_id'] ?? null)) {
            $this->ensureUserHasAccessToTeam($filters['team_id'], 'Você não tem acesso a esse time.');
        }

        if ($leagueIds !== null && filled($filters['game_id'] ?? null)
            && ! $this->gameRepository->existsInLeagues($filters['game_id'], $leagueIds)) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa partida.']);
        }

        return $this->repository->index($filters, $leagueIds);
    }

    public function store(array $data): GameEvent
    {
        $game = Game::find($data['game_id']);
        $this->ensureUserHasAccessToLeagues([$game->league_id]);
        $data['league_id'] = $game->league_id;
        $this->ensureGroupResultsEditable($game);
        $scoring = $this->eventTypeRepository->hasScoringType([$data['type']]);

        return DB::transaction(function () use ($data, $game, $scoring) {
            if ($scoring) {
                $this->knockoutService->ensureKnockoutResultsEditable($game);
            }

            $event = $this->repository->store($data);
            $scores = $this->refreshScores([$game->id]);
            $this->auditLogger->record('game_event.created', $event, new: $event->auditAttributes(), meta: ['scores' => $scores]);
            $this->cacheVersion->bump((int) $event->league_id);

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
        $previousLeagueId = (int) $event->league_id;
        $event->fill($data);

        $lockedGames = [];

        if ($event->isDirty(['game_id', 'team_id', 'type'])) {
            $this->ensureGroupResultsEditable($previousGame);

            if ($event->isDirty('game_id')) {
                $this->ensureGroupResultsEditable($game);
            }

            if ($this->eventTypeRepository->hasScoringType([$event->getOriginal('type'), $event->type])) {
                $lockedGames = array_filter([$previousGame, $event->isDirty('game_id') ? $game : null]);
            }
        }

        DB::transaction(function () use ($event, $previousGameId, $previousLeagueId, $lockedGames) {
            $this->ensureKnockoutResultsEditable($lockedGames);
            $changes = $event->auditChanges();
            $this->repository->update($event);
            $scores = $this->refreshScores([$previousGameId, $event->game_id]);

            if ($changes['new'] !== []) {
                $this->auditLogger->record('game_event.updated', $event, $changes['old'], $changes['new'], ['scores' => $scores]);
            }

            $this->cacheVersion->bump($previousLeagueId, (int) $event->league_id);
        });

        return $event;
    }

    public function destroy(string $id): ?bool
    {
        $event = $this->show($id);
        $this->ensureGroupResultsEditable($event->game);
        $scoring = $this->eventTypeRepository->hasScoringType([$event->type]);

        return DB::transaction(function () use ($event, $scoring) {
            if ($scoring && $event->game) {
                $this->knockoutService->ensureKnockoutResultsEditable($event->game);
            }

            $deleted = $this->repository->destroy($event);
            $scores = $this->refreshScores([$event->game_id]);

            if ($deleted) {
                $this->auditLogger->record('game_event.deleted', $event, old: $event->auditAttributes(), meta: ['scores' => $scores]);
                $this->cacheVersion->bump((int) $event->league_id);
            }

            return $deleted;
        });
    }

    private function ensureGroupResultsEditable(?Game $game): void
    {
        if ($game && $this->knockoutService->groupResultsLocked($game)) {
            throw ValidationException::withMessages(['message' => LeagueKnockoutService::GROUP_RESULTS_LOCKED]);
        }
    }

    private function ensureKnockoutResultsEditable(array $games): void
    {
        usort($games, fn (Game $a, Game $b) => (int) $a->league_id <=> (int) $b->league_id);

        foreach ($games as $game) {
            $this->knockoutService->ensureKnockoutResultsEditable($game);
        }
    }

    private function refreshScores(array $gameIds): array
    {
        $gameIds = array_unique(array_map('intval', $gameIds));
        sort($gameIds);

        return array_values(array_filter(array_map(
            fn (int $gameId) => $this->gameService->refreshScore($gameId),
            $gameIds,
        )));
    }
}

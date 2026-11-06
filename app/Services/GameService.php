<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameService
{
    use EnsuresLeagueAccess;

    private const SHOW_RELATIONS = ['homeTeam', 'awayTeam', 'stadium', 'events.player', 'events.relatedPlayer'];

    private const BACKOFFICE_RELATIONS = ['homeTeam.players', 'awayTeam.players'];

    private const STATUSES_WITH_SCORE = ['in_progress', 'finished'];

    public function __construct(private readonly GameRepository $repository) {}

    public function index(string $leagueId, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $user = Auth::user();
        if (! $user->isMaster() && ! $user->hasLeagueId($leagueId)) {
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
        }

        return $this->repository->index($leagueId, $filters, $paginate);
    }

    public function store(array $data): Game
    {
        $this->ensureUserHasAccessToLeagues([$data['league_id']]);

        if (in_array($data['status'], self::STATUSES_WITH_SCORE, true)) {
            $data['home_score'] = 0;
            $data['away_score'] = 0;
        }

        return $this->repository->store($data);
    }

    public function show(string $id): Game
    {
        return $this->find($id, $this->showRelations(backoffice: true));
    }

    public function publicShow(string $id): Game
    {
        return $this->find($id, $this->showRelations());
    }

    public function update(array $data, string $id): Game
    {
        $game = $this->find($id);

        if (array_key_exists('league_id', $data)) {
            $this->ensureUserHasAccessToLeagues([$data['league_id']]);
        }

        $game->fill($data);
        $leagueChanged = $game->isDirty('league_id');
        $recalculateScore = $this->shouldRecalculateScore($game);

        DB::transaction(function () use ($game, $leagueChanged, $recalculateScore) {
            if ($recalculateScore) {
                $game->fill($this->scoreFromEvents($game));
            }

            $this->repository->update($game);

            if ($leagueChanged) {
                $this->repository->syncEventsLeague($game);
            }
        });

        return $game->load($this->showRelations(backoffice: true));
    }

    public function refreshScore(int $gameId): void
    {
        $game = $this->repository->findForUpdate($gameId);

        if (! $game) {
            return;
        }

        $game->fill($this->scoreFromEvents($game));
        $this->repository->update($game);
    }

    private function scoreFromEvents(Game $game): array
    {
        $score = ['home_score' => 0, 'away_score' => 0];

        foreach ($this->repository->scoringEventCounts($game) as $row) {
            $isHome = (int) $row->team_id === (int) $game->home_team_id;
            $isAway = (int) $row->team_id === (int) $game->away_team_id;

            if (! $isHome && ! $isAway) {
                continue;
            }

            $creditsHome = $row->counts_for === 'opponent' ? $isAway : $isHome;
            $score[$creditsHome ? 'home_score' : 'away_score'] += (int) $row->total;
        }

        return $score;
    }

    private function shouldRecalculateScore(Game $game): bool
    {
        $hasScore = $game->home_score !== null && $game->away_score !== null;

        if (! $hasScore) {
            return in_array($game->status, self::STATUSES_WITH_SCORE, true);
        }

        return $game->isDirty(['home_team_id', 'away_team_id']);
    }

    public function destroy(string $id): ?bool
    {
        $game = $this->find($id);

        return $this->repository->destroy($game);
    }

    private function showRelations(bool $backoffice = false): array
    {
        return [
            'events' => fn ($query) => $query->latestMinuteFirst(),
            ...self::SHOW_RELATIONS,
            ...($backoffice ? self::BACKOFFICE_RELATIONS : []),
        ];
    }

    private function find(string $id, array $relations = []): Game
    {
        $game = $this->repository->show($id, $relations);

        if (! $game) {
            throw ValidationException::withMessages(['message' => 'Partida não encontrada!']);
        }

        return $game;
    }
}

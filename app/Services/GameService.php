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

    public function __construct(
        private readonly GameRepository $repository,
        private readonly LeagueDrawService $drawService,
        private readonly LeagueKnockoutService $knockoutService,
    ) {}

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
        return $this->withPenaltiesAllowed($this->find($id, $this->showRelations(backoffice: true)));
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
        $drawnPairingChanged = $this->changesDrawnPairing($game);

        DB::transaction(function () use ($game, $leagueChanged, $recalculateScore, $drawnPairingChanged) {
            if ($drawnPairingChanged) {
                $this->drawService->ensureTeamChangeKeepsSchedule($game);
            }

            if ($recalculateScore) {
                $game->fill($this->scoreFromEvents($game));
            }

            $this->repository->update($game);

            if ($leagueChanged) {
                $this->repository->syncEventsLeague($game);
            }

            $this->knockoutService->clearStalePenalties($game);
        });

        return $this->withPenaltiesAllowed($game->load($this->showRelations(backoffice: true)));
    }

    public function refreshScore(int $gameId): void
    {
        $game = $this->repository->findForUpdate($gameId);

        if (! $game) {
            return;
        }

        $game->fill($this->scoreFromEvents($game));
        $this->repository->update($game);
        $this->knockoutService->clearStalePenalties($game);
    }

    private function withPenaltiesAllowed(Game $game): Game
    {
        $game->penaltiesAllowed = $this->knockoutService->penaltiesAllowed($game);

        return $game;
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

    private function changesDrawnPairing(Game $game): bool
    {
        if ($game->group_number === null || ! $game->isDirty(['home_team_id', 'away_team_id'])) {
            return false;
        }

        $before = [(int) $game->getOriginal('home_team_id'), (int) $game->getOriginal('away_team_id')];
        $after = [(int) $game->home_team_id, (int) $game->away_team_id];
        sort($before);
        sort($after);

        return $before !== $after;
    }

    public function destroy(string $id): ?bool
    {
        $game = $this->find($id);

        if ($game->group_number !== null) {
            throw ValidationException::withMessages([
                'message' => 'Partidas de rodada sorteada não podem ser excluídas; desfaça a rodada no sorteio.',
            ]);
        }

        if ($game->isGeneratedKnockout()) {
            throw ValidationException::withMessages([
                'message' => 'Partidas geradas pelo mata-mata não podem ser excluídas; desfaça a fase no mata-mata.',
            ]);
        }

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

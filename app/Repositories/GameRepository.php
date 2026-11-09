<?php

namespace App\Repositories;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\GamePenaltyKick;
use App\Models\League;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Pagination\LengthAwarePaginator;

class GameRepository
{
    public function index(string $leagueId, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $query = Game::query()->with(['homeTeam', 'awayTeam', 'stadium'])->where('league_id', $leagueId);

        $query = $this->applyFilters($query, $filters);
        $query = $this->orderedForListing($query);

        if ($paginate) {
            $page = $filters['page'] ?? 1;
            $per_page = $filters['per_page'] ?? 20;

            return $query->paginate(perPage: (int) $per_page, page: (int) $page);
        }

        return $query->get();
    }

    public function store(array $data): Game
    {
        return Game::create($data);
    }

    public function show(string $id, array $with = []): ?Game
    {
        return Game::with($with)->find($id);
    }

    public function update(Game $game): bool
    {
        return $game->save();
    }

    public function findForUpdate(int $id): ?Game
    {
        return Game::query()->lockForUpdate()->find($id);
    }

    public function scoringEventCounts(Game $game): Collection
    {
        return $game->events()
            ->joinEventType()
            ->whereNotNull('game_event_types.counts_for')
            ->groupBy('game_events.team_id', 'game_event_types.counts_for')
            ->selectRaw('game_events.team_id as team_id, game_event_types.counts_for as counts_for, COUNT(*) as total')
            ->get();
    }

    public function replacePenaltyKicks(Game $game, array $kicks): void
    {
        $this->deletePenaltyKicks($game);

        if ($kicks === []) {
            return;
        }

        $now = now();

        GamePenaltyKick::query()->insert(array_map(
            fn (array $kick) => [...$kick, 'game_id' => $game->id, 'created_at' => $now, 'updated_at' => $now],
            $kicks,
        ));
    }

    public function deletePenaltyKicks(Game $game): int
    {
        return $game->penaltyKicks()->delete();
    }

    public function syncEventsLeague(Game $game): int
    {
        return $game->events()->update(['league_id' => $game->league_id]);
    }

    public function destroy(Game $game): ?bool
    {
        return $game->delete();
    }

    public function drawnGames(League $league): Collection
    {
        return $league->games()
            ->whereNotNull('group_number')
            ->orderBy('round')
            ->orderBy('id')
            ->get(['id', 'league_id', 'home_team_id', 'away_team_id', 'round', 'group_number', 'status']);
    }

    public function manualRoundGamesSummary(League $league): array
    {
        return [
            'total' => $this->manualRoundGames($league)->count(),
            'with_results' => $this->manualRoundGames($league)
                ->where(fn (Builder $q) => $q->where('status', '!=', 'scheduled')->orWhereHas('events'))
                ->count(),
        ];
    }

    public function deleteManualRoundGames(League $league): int
    {
        GameEvent::query()->whereIn('game_id', $this->manualRoundGames($league)->select('id'))->delete();

        return $this->manualRoundGames($league)->delete();
    }

    public function hasStartedDrawnGames(League $league, ?int $round = null): bool
    {
        return $league->games()
            ->whereNotNull('group_number')
            ->when($round !== null, fn (Builder $q) => $q->where('round', $round))
            ->where(fn (Builder $q) => $q->where('status', '!=', 'scheduled')->orWhereHas('events'))
            ->exists();
    }

    public function insertDrawnGames(array $rows): void
    {
        $this->insertRows($rows);
    }

    public function insertKnockoutGames(array $rows): void
    {
        $this->insertRows($rows);
    }

    public function knockoutGames(League $league): Collection
    {
        return $league->games()
            ->whereNotNull('knockout_stage')
            ->orderBy('phase_order')
            ->orderBy('tie_key')
            ->orderBy('leg')
            ->get();
    }

    public function tieGames(Game $game): Collection
    {
        return Game::query()
            ->where('league_id', $game->league_id)
            ->where('knockout_stage', $game->knockout_stage)
            ->where('tie_key', $game->tie_key)
            ->orderBy('leg')
            ->get();
    }

    public function hasKnockoutGamesInStages(int $leagueId, array $stages): bool
    {
        return $stages !== [] && Game::query()
            ->where('league_id', $leagueId)
            ->whereIn('knockout_stage', $stages)
            ->exists();
    }

    public function hasStartedKnockoutGames(League $league, array $stages): bool
    {
        return $league->games()
            ->whereIn('knockout_stage', $stages)
            ->where(fn (Builder $q) => $q->where('status', '!=', 'scheduled')->orWhereHas('events'))
            ->exists();
    }

    public function deleteKnockoutGames(League $league, array $stages): int
    {
        return $league->games()->whereIn('knockout_stage', $stages)->delete();
    }

    public function pendingDrawnGamesCount(League $league): int
    {
        return $league->games()
            ->whereNotNull('group_number')
            ->whereNotIn('status', ['finished', 'cancelled'])
            ->count();
    }

    public function cancelledGroupGamesCount(League $league): int
    {
        return $league->games()
            ->whereNotNull('round')
            ->where('status', 'cancelled')
            ->count();
    }

    public function manualPhaseGamesSummary(League $league): array
    {
        return [
            'total' => $this->manualPhaseGames($league)->count(),
            'with_results' => $this->manualPhaseGames($league)
                ->where(fn (Builder $q) => $q->where('status', '!=', 'scheduled')->orWhereHas('events'))
                ->count(),
        ];
    }

    public function deleteManualPhaseGames(League $league): int
    {
        GameEvent::query()->whereIn('game_id', $this->manualPhaseGames($league)->select('id'))->delete();

        return $this->manualPhaseGames($league)->delete();
    }

    private function insertRows(array $rows): void
    {
        $now = now();

        Game::query()->insert(array_map(
            fn (array $row) => [...$row, 'created_at' => $now, 'updated_at' => $now],
            $rows,
        ));
    }

    private function manualPhaseGames(League $league): HasMany
    {
        return $league->games()->whereNull('knockout_stage')->whereNotNull('phase');
    }

    public function deleteDrawnGames(League $league, ?int $round = null): int
    {
        return $league->games()
            ->whereNotNull('group_number')
            ->when($round !== null, fn (Builder $q) => $q->where('round', $round))
            ->delete();
    }

    private function manualRoundGames(League $league): HasMany
    {
        return $league->games()->whereNull('group_number')->whereNotNull('round');
    }

    private function applyFilters(Builder $query, array $filters = []): Builder
    {
        $query->when(filled($filters['round'] ?? null), function (Builder $q) use ($filters) {
            $q->where('round', $filters['round']);
        });

        $query->when(filled($filters['phase'] ?? null), function (Builder $q) use ($filters) {
            $q->where('phase', $filters['phase']);
        });

        $query->when(filled($filters['status'] ?? null), function (Builder $q) use ($filters) {
            $q->where('status', $filters['status']);
        });

        $query->when(filled($filters['team_id'] ?? null), function (Builder $q) use ($filters) {
            $teamId = $filters['team_id'];
            $q->where(fn (Builder $sub) => $sub->where('home_team_id', $teamId)->orWhere('away_team_id', $teamId));
        });

        return $query;
    }

    private function orderedForListing(Builder $query): Builder
    {
        return $query
            ->orderByRaw('round IS NULL, round')
            ->orderByRaw('phase_order IS NULL, phase_order')
            ->orderBy('match_date')
            ->orderBy('id');
    }
}

<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Models\LeagueDrawPlan;
use App\Models\Team;
use App\Repositories\GameRepository;
use App\Repositories\LeagueDrawPlanRepository;
use App\Repositories\LeagueRepository;
use App\Services\Draw\DrawState;
use App\Services\Draw\RoundRobinScheduler;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeagueDrawService
{
    private const FIRST_TURN = 1;

    private const DRAW_FORMATS = ['round_robin', 'hybrid'];

    private const NOTHING_DRAWN = 'Nenhuma rodada foi sorteada.';

    private const ALL_DRAWN = 'Todas as rodadas já foram sorteadas.';

    public function __construct(
        private readonly LeagueRepository $leagueRepository,
        private readonly GameRepository $gameRepository,
        private readonly LeagueDrawPlanRepository $planRepository,
        private readonly RoundRobinScheduler $scheduler,
    ) {}

    public function build(League $league): array
    {
        return $this->format($this->state($league));
    }

    public function allRoundsDrawn(League $league): bool
    {
        $state = $this->state($league);

        return $state->totalRounds() !== null && $state->drawnRounds() > 0 && $state->nextRound() === null;
    }

    public function drawNextRound(string $id, int $round, bool $replaceManualRounds = false): array
    {
        return DB::transaction(function () use ($id, $round, $replaceManualRounds) {
            $state = $this->state($this->lockLeague($id));

            $this->failWhen($this->structureIssue($state));
            $this->ensureIsNextRound($state, $round);
            $this->persistSingleGroup($state);

            $replacing = $replaceManualRounds && $this->canReplaceManualRounds($state);
            $manualGames = $this->gameRepository->manualRoundGamesSummary($state->league);
            $this->failWhen($this->manualRoundsIssue($state, $manualGames['total'], $replacing));

            if ($replacing && $manualGames['total'] > 0) {
                $this->gameRepository->deleteManualRoundGames($state->league);
            }

            $plans = $this->planRepository->forTurn($state->league, self::FIRST_TURN);
            $rows = [];

            foreach ($state->groupNumbers() as $groupNumber) {
                $groupRows = $state->turnOf($round) === self::FIRST_TURN
                    ? $this->drawFirstTurnRound($state, $groupNumber, $round, $plans->get($groupNumber))
                    : $this->mirrorRound($state, $groupNumber, $round);

                array_push($rows, ...$groupRows);
            }

            $this->gameRepository->insertDrawnGames($rows);

            return $this->format($this->state($state->league));
        });
    }

    public function undoLastRound(string $id, int $round): array
    {
        return DB::transaction(function () use ($id, $round) {
            $state = $this->state($this->lockLeague($id));
            $lastRound = $state->drawnRounds();

            if ($lastRound > 0 && $round !== $lastRound) {
                $this->failWhen(sprintf('A rodada %d não é a última sorteada (última: %d).', $round, $lastRound));
            }

            $this->failWhen($this->undoIssue($state));

            if ($lastRound === 1) {
                $this->planRepository->deleteForLeague($state->league);
            } elseif ($state->turnOf($lastRound) === self::FIRST_TURN) {
                $this->returnRoundToPlans($state, $lastRound);
            }

            $this->gameRepository->deleteDrawnGames($state->league, $lastRound);

            return $this->format($this->state($state->league));
        });
    }

    public function reset(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $state = $this->state($this->lockLeague($id));

            $this->failWhen($this->resetIssue($state));

            $this->gameRepository->deleteDrawnGames($state->league);
            $this->planRepository->deleteForLeague($state->league);

            return $this->format($this->state($state->league));
        });
    }

    public function ensureTeamChangeKeepsSchedule(Game $game): void
    {
        $state = $this->state($this->lockLeague((string) $game->league_id));
        $groupNumber = (int) $game->group_number;
        $round = (int) $game->round;
        $roundsPerTurn = $state->roundsPerTurn;

        if ($roundsPerTurn === null) {
            $this->failWhen('Não foi possível validar a troca porque os grupos da liga estão incompletos.');
        }

        if ($state->turnOf($round) !== self::FIRST_TURN) {
            $this->failWhen('No returno espelhado os confrontos seguem o turno; só é possível inverter o mando.');
        }

        $homeTeamId = (int) $game->home_team_id;
        $awayTeamId = (int) $game->away_team_id;
        $teamIds = $state->teamIds($groupNumber);

        if (! in_array($homeTeamId, $teamIds, true) || ! in_array($awayTeamId, $teamIds, true)) {
            $this->failWhen(sprintf('Os dois times precisam ser do grupo %s, o mesmo da partida.', $this->groupLetter($groupNumber)));
        }

        $otherGames = $state->groupGames($groupNumber)
            ->where('id', '!=', $game->id)
            ->filter(fn (Game $other) => $other->round <= $roundsPerTurn);

        foreach ($otherGames->where('round', $round) as $other) {
            foreach ([$homeTeamId, $awayTeamId] as $teamId) {
                if (in_array($teamId, [(int) $other->home_team_id, (int) $other->away_team_id], true)) {
                    $this->failWhen(sprintf('O time %s já joga na rodada %d.', $state->team($teamId)?->name, $round));
                }
            }
        }

        $rounds = $state->roundsOf($otherGames->push($game));
        $repeated = $this->scheduler->repeatedPair($this->scheduler->withByes($teamIds, $rounds));

        if ($repeated !== null) {
            $this->failWhen(in_array(RoundRobinScheduler::BYE, $repeated, true)
                ? sprintf('Com essa troca, o time %s folgaria duas vezes no turno.', $state->team(max($repeated))?->name)
                : 'Esse confronto já aconteceu neste turno.');
        }

        $roundsLeft = $roundsPerTurn - min($state->drawnRounds(), $roundsPerTurn);
        $completion = $this->scheduler->complete($teamIds, $this->scheduler->withByes($teamIds, $rounds), $roundsLeft);

        if ($completion === null) {
            $this->failWhen('Essa troca impede completar a tabela sem repetir confronto.');
        }

        if ($roundsLeft > 0) {
            $this->planRepository->save($state->league, $groupNumber, self::FIRST_TURN, $completion);
        }
    }

    private function persistSingleGroup(DrawState $state): void
    {
        if ((int) $state->league->groups_count !== 1) {
            return;
        }

        $needsUpdate = $state->teams->contains(
            fn (Team $team) => $team->pivot->group_number === null || (int) $team->pivot->group_number !== 1,
        );

        if ($needsUpdate) {
            $this->leagueRepository->assignAllToSingleGroup($state->league);
        }
    }

    private function drawFirstTurnRound(DrawState $state, int $groupNumber, int $round, ?LeagueDrawPlan $stored): array
    {
        $teamIds = $state->teamIds($groupNumber);
        $games = $state->groupGames($groupNumber);
        $played = $this->scheduler->withByes($teamIds, $state->roundsOf($games));
        $roundsLeft = $state->roundsPerTurn - count($played);
        $plan = $stored?->rounds();

        if (! is_array($plan) || count($plan) !== $roundsLeft) {
            $plan = $round === 1
                ? $this->scheduler->circlePlan($teamIds)
                : $this->scheduler->complete($teamIds, $played, $roundsLeft);
        }

        if (empty($plan)) {
            $this->failWhen('Não foi possível sortear a rodada; tente novamente.');
        }

        ['round' => $pairs, 'plan' => $rest] = $this->scheduler->nextRound($teamIds, $played, $plan);
        $this->planRepository->save($state->league, $groupNumber, self::FIRST_TURN, $rest);

        $oriented = $this->scheduler->orient(
            $teamIds,
            $games->map(fn (Game $game) => [(int) $game->home_team_id, (int) $game->away_team_id])->all(),
            $pairs,
            $games->where('round', $round - 1)->pluck('away_team_id')->all(),
        );

        return array_map(
            fn (array $teams) => $this->gameRow($state->league, $groupNumber, $round, $teams[0], $teams[1]),
            $oriented,
        );
    }

    private function mirrorRound(DrawState $state, int $groupNumber, int $round): array
    {
        $sourceRound = $round - $state->roundsPerTurn * ($state->turnOf($round) - 1);

        return $state->groupGames($groupNumber)
            ->where('round', $sourceRound)
            ->map(fn (Game $game) => $this->gameRow(
                $state->league,
                $groupNumber,
                $round,
                (int) $game->away_team_id,
                (int) $game->home_team_id,
            ))
            ->values()
            ->all();
    }

    private function returnRoundToPlans(DrawState $state, int $round): void
    {
        $plans = $this->planRepository->forTurn($state->league, self::FIRST_TURN);

        foreach ($state->groupNumbers() as $groupNumber) {
            $teamIds = $state->teamIds($groupNumber);
            $roundGames = $state->groupGames($groupNumber)->where('round', $round);
            [$pairs] = $this->scheduler->withByes($teamIds, $state->roundsOf($roundGames));

            $this->planRepository->save(
                $state->league,
                $groupNumber,
                self::FIRST_TURN,
                [$pairs, ...($plans->get($groupNumber)?->rounds() ?? [])],
            );
        }
    }

    private function gameRow(League $league, int $groupNumber, int $round, int $homeTeamId, int $awayTeamId): array
    {
        return [
            'league_id' => $league->id,
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
            'stadium_id' => null,
            'match_date' => null,
            'home_score' => null,
            'away_score' => null,
            'round' => $round,
            'group_number' => $groupNumber,
            'phase' => null,
            'phase_order' => null,
            'tie_key' => null,
            'leg' => null,
            'status' => 'scheduled',
        ];
    }

    private function ensureIsNextRound(DrawState $state, int $round): void
    {
        $drawnRounds = $state->drawnRounds();

        if ($round <= $drawnRounds) {
            $this->failWhen(sprintf('A rodada %d já foi sorteada.', $round));
        }

        if ($state->nextRound() === null) {
            $this->failWhen(self::ALL_DRAWN);
        }

        if ($round !== $drawnRounds + 1) {
            $this->failWhen(sprintf('A próxima rodada a ser sorteada é a %d.', $drawnRounds + 1));
        }
    }

    private function structureIssue(DrawState $state): ?string
    {
        $league = $state->league;

        return match (true) {
            ! $league->usesDraw() => 'O sorteio de rodadas só está disponível em ligas com sorteio.',
            ! in_array($league->format, self::DRAW_FORMATS, true) => 'O sorteio de rodadas só está disponível em ligas de pontos corridos ou com fase de grupos.',
            $state->teams->isEmpty() => 'A liga não tem times para sortear.',
            $state->hasUnassignedTeams => 'Todos os times da liga precisam estar em um grupo antes do sorteio.',
            ! $state->hasEqualGroups => 'Todos os grupos precisam ter o mesmo número de times antes do sorteio.',
            $state->groupSize < 2 => 'Cada grupo precisa ter pelo menos 2 times.',
            default => null,
        };
    }

    private function canReplaceManualRounds(DrawState $state): bool
    {
        return $state->drawnRounds() === 0;
    }

    private function manualRoundsIssue(DrawState $state, int $total, bool $replacing): ?string
    {
        if ($total === 0 || $replacing) {
            return null;
        }

        $games = $total === 1
            ? '1 partida de rodada cadastrada manualmente'
            : sprintf('%d partidas de rodada cadastradas manualmente', $total);

        if ($this->canReplaceManualRounds($state)) {
            return sprintf('A liga já tem %s; confirme a substituição para sortear.', $games);
        }

        return sprintf('A liga já tem %s; %s para continuar o sorteio.', $games, $total === 1 ? 'remova-a' : 'remova-as');
    }

    private function drawIssue(DrawState $state, int $manualTotal): ?string
    {
        return $this->structureIssue($state)
            ?? $this->manualRoundsIssue($state, $manualTotal, $this->canReplaceManualRounds($state))
            ?? ($state->nextRound() === null ? self::ALL_DRAWN : null);
    }

    private function undoIssue(DrawState $state): ?string
    {
        $lastRound = $state->drawnRounds();

        if ($lastRound === 0) {
            return self::NOTHING_DRAWN;
        }

        if ($this->leagueRepository->hasKnockoutGames($state->league)) {
            return sprintf('A rodada %d não pode ser desfeita porque o mata-mata já foi gerado; desfaça as fases do mata-mata antes.', $lastRound);
        }

        if ($this->gameRepository->hasStartedDrawnGames($state->league, $lastRound)) {
            return sprintf('A rodada %d não pode ser desfeita porque tem partidas que já começaram ou têm eventos.', $lastRound);
        }

        return null;
    }

    private function resetIssue(DrawState $state): ?string
    {
        if ($state->drawnRounds() === 0) {
            return self::NOTHING_DRAWN;
        }

        if ($this->leagueRepository->hasKnockoutGames($state->league)) {
            return 'O sorteio não pode ser reiniciado porque o mata-mata já foi gerado; desfaça as fases do mata-mata antes.';
        }

        if ($this->gameRepository->hasStartedDrawnGames($state->league)) {
            return 'O sorteio não pode ser reiniciado porque há partidas de grupo que já começaram ou têm eventos.';
        }

        return null;
    }

    private function format(DrawState $state): array
    {
        $league = $state->league;
        $manualGames = $this->gameRepository->manualRoundGamesSummary($league);
        $drawIssue = $this->drawIssue($state, $manualGames['total']);
        $undoIssue = $this->undoIssue($state);
        $resetIssue = $this->resetIssue($state);

        return [
            'league_id' => $league->id,
            'format' => $league->format,
            'fixture_mode' => $league->fixture_mode,
            'groups_count' => $league->groups_count,
            'group_legs' => $league->group_legs,
            'group_size' => $state->groupSize,
            'rounds_per_turn' => $state->roundsPerTurn,
            'total_rounds' => $state->totalRounds(),
            'drawn_rounds' => $state->drawnRounds(),
            'next_round' => $state->nextRound(),
            'can_draw' => $drawIssue === null,
            'draw_blocked_reason' => $drawIssue,
            'can_undo' => $undoIssue === null,
            'undo_blocked_reason' => $undoIssue,
            'can_reset' => $resetIssue === null,
            'reset_blocked_reason' => $resetIssue,
            'manual_round_games' => $manualGames,
            'groups' => array_map(fn (int $groupNumber) => [
                'group_number' => $groupNumber,
                'byes' => $this->byes($state, $groupNumber),
            ], $state->groupNumbers()),
        ];
    }

    private function byes(DrawState $state, int $groupNumber): array
    {
        $teamIds = $state->teamIds($groupNumber);

        if (count($teamIds) % 2 === 0) {
            return [];
        }

        $games = $state->groupGames($groupNumber);
        $byes = [];

        for ($round = 1; $round <= $state->drawnRounds(); $round++) {
            $roundGames = $games->where('round', $round);
            $playing = [...$roundGames->pluck('home_team_id')->all(), ...$roundGames->pluck('away_team_id')->all()];

            foreach (array_diff($teamIds, array_map('intval', $playing)) as $teamId) {
                $byes[] = ['round' => $round, 'team' => $this->teamSummary($state->team($teamId))];
            }
        }

        return $byes;
    }

    private function teamSummary(Team $team): array
    {
        return ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logoUrl()];
    }

    private function groupLetter(int $groupNumber): string
    {
        return chr(64 + $groupNumber);
    }

    private function state(League $league): DrawState
    {
        return new DrawState(
            $league,
            $this->leagueRepository->groupTeams($league),
            $this->gameRepository->drawnGames($league),
        );
    }

    private function lockLeague(string $id): League
    {
        $league = $this->leagueRepository->findForUpdate($id);

        if (! $league) {
            $this->failWhen('Liga não encontrada!');
        }

        return $league;
    }

    private function failWhen(?string $message): void
    {
        if ($message !== null) {
            throw ValidationException::withMessages(['message' => $message]);
        }
    }
}

<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Repositories\GameRepository;
use App\Repositories\LeagueRepository;
use App\Services\Knockout\KnockoutBracket;
use App\Services\Knockout\TieResolver;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeagueKnockoutService
{
    private const NOT_ENABLED = 'O mata-mata pela classificação só está disponível em ligas com fase de grupos e sorteio.';

    private const NOT_CONFIGURED = 'Configure o mata-mata no regulamento da liga antes de gerar as fases.';

    private const ROUNDS_PENDING = 'Sorteie todas as rodadas da fase de grupos antes de gerar o mata-mata.';

    private const ALL_GENERATED = 'Todas as fases do mata-mata já foram geradas.';

    private const NOTHING_GENERATED = 'Nenhuma fase do mata-mata foi gerada.';

    private const PENALTIES_NOT_GENERATED = 'Pênaltis só podem ser lançados em confrontos de mata-mata gerados pela classificação.';

    private const PENALTIES_NOT_IN_RULES = 'O regulamento desta liga não decide confrontos nos pênaltis.';

    private const PENALTIES_NOT_DECISIVE = 'Os pênaltis são lançados no jogo de volta do confronto.';

    private const PENALTIES_NOT_FINISHED = 'Os pênaltis só podem ser lançados depois que todos os jogos do confronto forem encerrados.';

    private const PENALTIES_NOT_LEVEL = 'O confronto não está empatado após os critérios de desempate; não há disputa de pênaltis.';

    public function __construct(
        private readonly LeagueRepository $leagueRepository,
        private readonly GameRepository $gameRepository,
        private readonly LeagueDrawService $drawService,
        private readonly StandingsService $standingsService,
        private readonly KnockoutBracket $bracket,
        private readonly TieResolver $tieResolver,
    ) {}

    public function build(League $league): array
    {
        return $this->format($league);
    }

    public function preview(League $league): array
    {
        $context = $this->context($league, withSeeding: true);

        $this->failWhen($this->generateIssue($context));

        return $this->previewOf($this->planNextStep($context), $context);
    }

    public function generateNextStage(string $id, string $stage, bool $replaceManualPhases = false): array
    {
        return DB::transaction(function () use ($id, $stage, $replaceManualPhases) {
            $league = $this->lockLeague($id);
            $context = $this->context($league, withSeeding: true);

            $this->failWhen($this->structureIssue($league));
            $this->ensureIsNextStage($context, $stage);
            $this->failWhen($this->generateIssue($context));

            $isFirstStage = $context['last_stage'] === null;
            $replacing = $replaceManualPhases && $isFirstStage;
            $manualGames = $this->gameRepository->manualPhaseGamesSummary($league);
            $this->failWhen($this->manualPhasesIssue($isFirstStage, $manualGames['total'], $replacing));

            $plan = $this->planNextStep($context);

            if ($replacing && $manualGames['total'] > 0) {
                $this->gameRepository->deleteManualPhaseGames($league);
            }

            $this->gameRepository->insertKnockoutGames($this->rowsOf($league, $plan));

            return $this->format($league);
        });
    }

    public function undoLastStage(string $id, string $stage): array
    {
        return DB::transaction(function () use ($id, $stage) {
            $league = $this->lockLeague($id);
            $context = $this->context($league);
            $lastStage = $context['last_stage'];

            if ($lastStage !== null && $stage !== $lastStage) {
                $this->failWhen(sprintf('A última fase gerada é: %s.', KnockoutBracket::STAGES[$lastStage]['label']));
            }

            $this->failWhen($this->undoIssue($context));

            $this->gameRepository->deleteKnockoutGames($league, $this->stepStages($lastStage));

            return $this->format($league);
        });
    }

    public function penaltiesIssue(Game $game, array $changes = []): ?string
    {
        if (! $game->isGeneratedKnockout()) {
            return self::PENALTIES_NOT_GENERATED;
        }

        $league = $this->leagueRepository->show((string) $game->league_id);

        if (! $league || ! $this->isEnabled($league)) {
            return self::PENALTIES_NOT_GENERATED;
        }

        $sequence = $league->knockout_tiebreakers ?? [];

        if (end($sequence) !== TieResolver::PENALTIES) {
            return self::PENALTIES_NOT_IN_RULES;
        }

        $legs = $this->gameRepository->tieGames($game)
            ->map(fn (Game $leg) => (int) $leg->id === (int) $game->id
                ? [...$this->tieResolver->legFromGame($game), ...$changes]
                : $this->tieResolver->legFromGame($leg))
            ->all();

        $decisive = $this->tieResolver->decisiveLeg($legs);

        if (! $decisive || (int) $decisive['id'] !== (int) $game->id) {
            return self::PENALTIES_NOT_DECISIVE;
        }

        $resolution = $this->tieResolver->resolve($legs, $sequence);

        if (! $resolution['finished']) {
            return self::PENALTIES_NOT_FINISHED;
        }

        return $resolution['penalties_reachable'] ? null : self::PENALTIES_NOT_LEVEL;
    }

    public function penaltiesAllowed(Game $game): bool
    {
        return $game->isGeneratedKnockout() && $this->penaltiesIssue($game) === null;
    }

    public function clearStalePenalties(Game $game): void
    {
        if (! $game->isGeneratedKnockout()) {
            return;
        }

        $tieGames = $this->gameRepository->tieGames($game);
        $decisive = $this->tieResolver->decisiveLeg($tieGames->map(fn (Game $leg) => $this->tieResolver->legFromGame($leg))->all());

        if (! $decisive) {
            return;
        }

        $decisiveGame = (int) $decisive['id'] === (int) $game->id ? $game : $tieGames->firstWhere('id', $decisive['id']);

        if ($decisiveGame->home_penalties === null && $decisiveGame->away_penalties === null) {
            return;
        }

        if ($this->penaltiesIssue($decisiveGame) !== null) {
            $decisiveGame->fill(['home_penalties' => null, 'away_penalties' => null]);
            $this->gameRepository->update($decisiveGame);
        }
    }

    private function format(League $league): array
    {
        $sequence = $league->knockout_tiebreakers ?? [];
        $context = $this->context($league, withSeeding: in_array('better_campaign', $sequence, true));
        $manualGames = $this->gameRepository->manualPhaseGamesSummary($league);
        $isFirstStage = $context['last_stage'] === null;
        $generateIssue = $this->generateIssue($context)
            ?? $this->manualPhasesIssue($isFirstStage, $manualGames['total'], $isFirstStage);
        $undoIssue = $this->undoIssue($context);
        $nextStage = $context['next_step'];

        return [
            'league_id' => $league->id,
            'enabled' => $this->isEnabled($league),
            'knockout_size' => $league->knockout_size,
            'knockout_legs' => $league->knockout_legs,
            'final_legs' => $league->final_legs,
            'third_place' => $league->third_place,
            'knockout_tiebreakers' => $league->knockout_tiebreakers,
            'stages' => $this->stagesOf($context),
            'generated_stages' => $context['generated'],
            'last_stage' => $context['last_stage'],
            'next_stage' => $nextStage === null ? null : [
                ...$this->stageInfo($league, $nextStage[0]),
                'with_third_place' => in_array(KnockoutBracket::THIRD_PLACE, $nextStage, true),
            ],
            'can_generate' => $generateIssue === null,
            'generate_blocked_reason' => $generateIssue,
            'can_undo' => $undoIssue === null,
            'undo_blocked_reason' => $undoIssue,
            'manual_phase_games' => $manualGames,
            'awaiting_penalties' => $this->awaitingPenalties($context),
        ];
    }

    private function context(League $league, bool $withSeeding = false): array
    {
        $games = $this->gameRepository->knockoutGames($league);
        $steps = $this->structureIssue($league) === null
            ? $this->bracket->steps((int) $league->knockout_size, (bool) $league->third_place)
            : [];
        $generated = array_values(array_filter(
            KnockoutBracket::stageKeys(),
            fn (string $key) => $games->contains('knockout_stage', $key),
        ));
        usort($generated, fn (string $a, string $b) => KnockoutBracket::STAGES[$a]['phase_order'] <=> KnockoutBracket::STAGES[$b]['phase_order']);

        $lastStage = null;
        foreach (KnockoutBracket::stepKeys() as $key) {
            if (in_array($key, $generated, true)) {
                $lastStage = $key;
            }
        }

        $nextStep = null;
        $mainKeys = array_map(fn (array $step) => $step[0], $steps);

        if ($lastStage === null) {
            $nextStep = $steps[0] ?? null;
        } elseif (($index = array_search($lastStage, $mainKeys, true)) !== false) {
            $nextStep = $steps[$index + 1] ?? null;
        }

        $seeding = $withSeeding && $this->isEnabled($league) ? $this->standingsService->seeding($league) : [];

        return [
            'league' => $league,
            'games' => $games,
            'steps' => $steps,
            'generated' => $generated,
            'last_stage' => $lastStage,
            'next_step' => $nextStep,
            'seeding' => $seeding,
            'seeds' => $this->standingsService->seedMap($seeding),
        ];
    }

    private function isEnabled(League $league): bool
    {
        return $league->format === 'hybrid' && $league->usesDraw();
    }

    private function structureIssue(League $league): ?string
    {
        if (! $this->isEnabled($league)) {
            return self::NOT_ENABLED;
        }

        $size = $league->knockout_size;
        $groupsCount = max(1, (int) $league->groups_count);

        $configured = $size !== null
            && in_array((int) $size, League::KNOCKOUT_SIZES, true)
            && $league->qualifiers_per_group !== null
            && $groupsCount * (int) $league->qualifiers_per_group === (int) $size
            && ($groupsCount === 1 || $groupsCount % 2 === 0)
            && $league->knockout_legs !== null
            && $league->final_legs !== null
            && in_array($league->knockout_tiebreakers, League::KNOCKOUT_TIEBREAKER_SEQUENCES, true);

        return $configured ? null : self::NOT_CONFIGURED;
    }

    private function generateIssue(array $context): ?string
    {
        $league = $context['league'];

        if ($issue = $this->structureIssue($league)) {
            return $issue;
        }

        if ($context['next_step'] === null) {
            return self::ALL_GENERATED;
        }

        if ($context['last_stage'] === null) {
            return $this->firstStageIssue($league);
        }

        $ties = $this->stageTies($context, $context['last_stage']);

        foreach ($ties as $tie) {
            if ($tie['resolution']['winner_team_id'] === null) {
                return sprintf('Há confrontos %s ainda não decididos.', KnockoutBracket::STAGES[$context['last_stage']]['of']);
            }
        }

        return null;
    }

    private function firstStageIssue(League $league): ?string
    {
        if (! $this->drawService->allRoundsDrawn($league)) {
            return self::ROUNDS_PENDING;
        }

        $pending = $this->gameRepository->pendingDrawnGamesCount($league);

        if ($pending === 1) {
            return 'Há 1 partida da fase de grupos que ainda não foi encerrada nem cancelada.';
        }

        if ($pending > 1) {
            return sprintf('Há %d partidas da fase de grupos que ainda não foram encerradas nem canceladas.', $pending);
        }

        return null;
    }

    private function manualPhasesIssue(bool $isFirstStage, int $total, bool $replacing): ?string
    {
        if ($total === 0 || $replacing) {
            return null;
        }

        $games = $total === 1
            ? '1 partida de mata-mata cadastrada manualmente'
            : sprintf('%d partidas de mata-mata cadastradas manualmente', $total);

        if ($isFirstStage) {
            return sprintf('A liga já tem %s; confirme a substituição para gerar o mata-mata.', $games);
        }

        return sprintf('A liga já tem %s; %s para continuar o mata-mata.', $games, $total === 1 ? 'remova-a' : 'remova-as');
    }

    private function undoIssue(array $context): ?string
    {
        $lastStage = $context['last_stage'];

        if ($lastStage === null) {
            return self::NOTHING_GENERATED;
        }

        $stages = $this->stepStages($lastStage);

        if ($this->gameRepository->hasStartedKnockoutGames($context['league'], $stages)) {
            $articles = array_map(fn (string $key) => KnockoutBracket::STAGES[$key]['article'], $this->presentStages($context, $stages));

            return sprintf(
                'Não é possível desfazer %s porque há partidas que já começaram ou têm eventos.',
                implode(' e ', $articles),
            );
        }

        return null;
    }

    private function ensureIsNextStage(array $context, string $stage): void
    {
        if (in_array($stage, $context['generated'], true)) {
            $info = KnockoutBracket::STAGES[$stage];
            $this->failWhen(ucfirst($info['article']).($info['plural'] ? ' já foram geradas.' : ' já foi gerada.'));
        }

        if ($context['next_step'] === null) {
            $this->failWhen(self::ALL_GENERATED);
        }

        if ($stage !== $context['next_step'][0]) {
            $this->failWhen(sprintf('A próxima fase a ser gerada é: %s.', KnockoutBracket::STAGES[$context['next_step'][0]]['label']));
        }
    }

    private function stepStages(string $mainStage): array
    {
        return $mainStage === KnockoutBracket::FINAL ? [KnockoutBracket::FINAL, KnockoutBracket::THIRD_PLACE] : [$mainStage];
    }

    private function presentStages(array $context, array $stages): array
    {
        $present = array_values(array_filter($stages, fn (string $key) => in_array($key, $context['generated'], true)));
        usort($present, fn (string $a, string $b) => $a === KnockoutBracket::FINAL ? -1 : ($b === KnockoutBracket::FINAL ? 1 : 0));

        return $present;
    }

    private function stageTies(array $context, string $stage): array
    {
        $sequence = $context['league']->knockout_tiebreakers ?? [];

        return $context['games']
            ->where('knockout_stage', $stage)
            ->groupBy('tie_key')
            ->map(fn (Collection $games, string $tieKey) => [
                'tie_key' => $tieKey,
                'number' => $this->tieNumber($tieKey),
                'games' => $games,
                'resolution' => $this->tieResolver->resolve(
                    $games->map(fn (Game $game) => $this->tieResolver->legFromGame($game))->all(),
                    $sequence,
                    $context['seeds'],
                ),
            ])
            ->sortBy('number')
            ->values()
            ->all();
    }

    private function tieNumber(string $tieKey): int
    {
        return (int) substr($tieKey, strrpos($tieKey, '-') + 1);
    }

    private function awaitingPenalties(array $context): array
    {
        $awaiting = [];

        foreach ($context['generated'] as $stage) {
            foreach ($this->stageTies($context, $stage) as $tie) {
                if ($tie['resolution']['awaiting_penalties']) {
                    $awaiting[] = [
                        'stage' => $stage,
                        'tie_key' => $tie['tie_key'],
                        'game_id' => $tie['resolution']['decisive_game_id'],
                    ];
                }
            }
        }

        return $awaiting;
    }

    private function stagesOf(array $context): array
    {
        $stages = array_merge(...($context['steps'] ?: [[]]));
        usort($stages, fn (string $a, string $b) => KnockoutBracket::STAGES[$a]['phase_order'] <=> KnockoutBracket::STAGES[$b]['phase_order']);

        return array_map(fn (string $key) => [
            ...$this->stageInfo($context['league'], $key),
            'generated' => in_array($key, $context['generated'], true),
        ], $stages);
    }

    private function stageInfo(League $league, string $key): array
    {
        return [
            'key' => $key,
            'label' => KnockoutBracket::STAGES[$key]['label'],
            'legs' => $this->stageLegs($league, $key),
        ];
    }

    private function stageLegs(League $league, string $key): int
    {
        return match ($key) {
            KnockoutBracket::THIRD_PLACE => 1,
            KnockoutBracket::FINAL => (int) $league->final_legs,
            default => (int) $league->knockout_legs,
        };
    }

    private function planNextStep(array $context): array
    {
        $step = $context['next_step'];
        $stage = $step[0];

        $ties = $context['last_stage'] === null
            ? $this->firstStageTies($context, $stage)
            : $this->followingStageTies($context, $stage);

        $thirdPlace = null;

        if (in_array(KnockoutBracket::THIRD_PLACE, $step, true)) {
            $semifinals = $this->stageTies($context, 'SF');

            if (count($semifinals) === 2) {
                $thirdPlace = $this->tieEntry(
                    $context,
                    KnockoutBracket::THIRD_PLACE.'-1',
                    ['team_id' => $semifinals[0]['resolution']['loser_team_id'], 'label' => 'Perdedor '.$semifinals[0]['tie_key']],
                    ['team_id' => $semifinals[1]['resolution']['loser_team_id'], 'label' => 'Perdedor '.$semifinals[1]['tie_key']],
                );
            }
        }

        return ['stage' => $stage, 'ties' => $ties, 'third_place' => $thirdPlace];
    }

    private function firstStageTies(array $context, string $stage): array
    {
        $league = $context['league'];
        $groupsCount = max(1, (int) $league->groups_count);
        $qualifiers = (int) $league->qualifiers_per_group;
        $groups = array_fill_keys(range(1, $groupsCount), []);

        foreach ($context['seeding'] as $row) {
            if ($row['group_number'] !== null && isset($groups[$row['group_number']])) {
                $groups[$row['group_number']][$row['position'] - 1] = $row['team_id'];
            }
        }

        foreach ($groups as $groupNumber => $teamIds) {
            if (count($teamIds) < $qualifiers) {
                $this->failWhen($groupsCount === 1
                    ? sprintf('A liga não tem %d times para classificar ao mata-mata.', $qualifiers)
                    : sprintf('O grupo %s não tem %d times para classificar ao mata-mata.', chr(64 + $groupNumber), $qualifiers));
            }

            ksort($groups[$groupNumber]);
        }

        $ties = [];

        foreach ($this->bracket->firstStagePairs($groups, $qualifiers) as $index => [$first, $second]) {
            $ties[] = $this->tieEntry(
                $context,
                $stage.'-'.($index + 1),
                ['team_id' => $first['team_id'], 'label' => $this->qualifierLabel($groupsCount, $first)],
                ['team_id' => $second['team_id'], 'label' => $this->qualifierLabel($groupsCount, $second)],
            );
        }

        return $ties;
    }

    private function followingStageTies(array $context, string $stage): array
    {
        $previous = $this->stageTies($context, $context['last_stage']);
        $ties = [];

        foreach (array_chunk($previous, 2) as $index => $pair) {
            if (count($pair) !== 2) {
                $this->failWhen('Não foi possível montar a próxima fase do mata-mata.');
            }

            $ties[] = $this->tieEntry(
                $context,
                $stage.'-'.($index + 1),
                ['team_id' => $pair[0]['resolution']['winner_team_id'], 'label' => 'Vencedor '.$pair[0]['tie_key']],
                ['team_id' => $pair[1]['resolution']['winner_team_id'], 'label' => 'Vencedor '.$pair[1]['tie_key']],
            );
        }

        return $ties;
    }

    private function qualifierLabel(int $groupsCount, array $slot): string
    {
        return $groupsCount === 1
            ? sprintf('%dº colocado', $slot['position'])
            : sprintf('%dº Grupo %s', $slot['position'], chr(64 + $slot['group_number']));
    }

    private function tieEntry(array $context, string $tieKey, array $first, array $second): array
    {
        $seeds = $context['seeds'];
        $firstSeed = $seeds[(int) $first['team_id']] ?? PHP_INT_MAX;
        $secondSeed = $seeds[(int) $second['team_id']] ?? PHP_INT_MAX;
        [$home, $away] = $secondSeed < $firstSeed ? [$second, $first] : [$first, $second];

        return [
            'tie_key' => $tieKey,
            'home_team_id' => (int) $home['team_id'],
            'away_team_id' => (int) $away['team_id'],
            'home_label' => $home['label'],
            'away_label' => $away['label'],
        ];
    }

    private function legsOf(League $league, string $stage, array $tie): array
    {
        if ($this->stageLegs($league, $stage) === 2) {
            return [
                ['leg' => 1, 'home_team_id' => $tie['away_team_id'], 'away_team_id' => $tie['home_team_id']],
                ['leg' => 2, 'home_team_id' => $tie['home_team_id'], 'away_team_id' => $tie['away_team_id']],
            ];
        }

        return [['leg' => null, 'home_team_id' => $tie['home_team_id'], 'away_team_id' => $tie['away_team_id']]];
    }

    private function plannedTies(array $plan): array
    {
        $ties = array_map(fn (array $tie) => [$plan['stage'], $tie], $plan['ties']);

        if ($plan['third_place'] !== null) {
            $ties[] = [KnockoutBracket::THIRD_PLACE, $plan['third_place']];
        }

        return $ties;
    }

    private function rowsOf(League $league, array $plan): array
    {
        $rows = [];

        foreach ($this->plannedTies($plan) as [$stage, $tie]) {
            foreach ($this->legsOf($league, $stage, $tie) as $leg) {
                $rows[] = [
                    'league_id' => $league->id,
                    'home_team_id' => $leg['home_team_id'],
                    'away_team_id' => $leg['away_team_id'],
                    'stadium_id' => null,
                    'match_date' => null,
                    'home_score' => null,
                    'away_score' => null,
                    'home_penalties' => null,
                    'away_penalties' => null,
                    'round' => null,
                    'group_number' => null,
                    'phase' => KnockoutBracket::STAGES[$stage]['label'],
                    'phase_order' => KnockoutBracket::STAGES[$stage]['phase_order'],
                    'tie_key' => $tie['tie_key'],
                    'leg' => $leg['leg'],
                    'knockout_stage' => $stage,
                    'status' => 'scheduled',
                ];
            }
        }

        return $rows;
    }

    private function previewOf(array $plan, array $context): array
    {
        $league = $context['league'];
        $teams = [];

        foreach ($context['seeding'] as $row) {
            $teams[(int) $row['team_id']] = $row['team'];
        }

        $previewTie = fn (string $stage, array $tie) => [
            'tie_key' => $tie['tie_key'],
            'home_team' => $teams[$tie['home_team_id']] ?? null,
            'away_team' => $teams[$tie['away_team_id']] ?? null,
            'home_label' => $tie['home_label'],
            'away_label' => $tie['away_label'],
            'legs' => $this->legsOf($league, $stage, $tie),
        ];

        return [
            'stage' => $this->stageInfo($league, $plan['stage']),
            'ties' => array_map(fn (array $tie) => $previewTie($plan['stage'], $tie), $plan['ties']),
            'third_place' => $plan['third_place'] === null ? null : $previewTie(KnockoutBracket::THIRD_PLACE, $plan['third_place']),
        ];
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

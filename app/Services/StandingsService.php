<?php

namespace App\Services;

use App\Models\Game;
use App\Models\League;
use App\Models\Team;
use App\Repositories\StandingsRepository;
use App\Services\Knockout\TieResolver;
use App\Services\Standings\StandingsSorter;
use Illuminate\Support\Collection;

class StandingsService
{
    public function __construct(
        private readonly StandingsRepository $repository,
        private readonly StandingsSorter $sorter,
        private readonly TieResolver $tieResolver,
    ) {}

    public function build(League $league): array
    {
        $result = [];
        $seeds = [];

        if (in_array($league->format, ['round_robin', 'hybrid'], true)) {
            ['result' => $result, 'seeding' => $seeding] = $this->groupStage($league);
            $seeds = $this->seedMap($seeding);
        }

        if (in_array($league->format, ['knockout', 'hybrid'], true)) {
            $result['phases'] = $this->phases($league, $seeds);
        }

        return $result;
    }

    public function seeding(League $league): array
    {
        return $this->groupStage($league)['seeding'];
    }

    public function seedMap(array $seeding): array
    {
        $seeds = [];

        foreach ($seeding as $row) {
            $seeds[(int) $row['team_id']] = (int) $row['seed'];
        }

        return $seeds;
    }

    private function groupStage(League $league): array
    {
        $leagueId = (int) $league->id;
        $tiebreakers = $league->effectiveTiebreakers();
        $teams = $this->repository->leagueTeams($leagueId)->all();
        $games = $this->repository->groupStageGames($leagueId)->map(fn ($game) => [
            'id' => (int) $game->id,
            'home_team_id' => (int) $game->home_team_id,
            'away_team_id' => (int) $game->away_team_id,
            'home_score' => $game->home_score === null ? null : (int) $game->home_score,
            'away_score' => $game->away_score === null ? null : (int) $game->away_score,
            'status' => $game->status,
            'group_number' => $game->group_number === null ? null : (int) $game->group_number,
        ])->all();
        $cards = $this->repository->groupStageCards($leagueId)->all();

        $standings = $this->table($teams, $games, $cards, $tiebreakers);
        $groupsCount = max(1, (int) $league->groups_count);

        if ($groupsCount === 1) {
            $groups = [['group_number' => 1, 'standings' => $standings]];
        } else {
            $groupByTeam = [];
            foreach ($teams as $team) {
                $groupByTeam[(int) $team->id] = $league->resolvedGroupNumber($team->group_number);
            }

            $groups = array_map(fn (int $groupNumber) => [
                'group_number' => $groupNumber,
                'standings' => $this->table(
                    array_values(array_filter($teams, fn (Team $team) => $groupByTeam[(int) $team->id] === $groupNumber)),
                    array_values(array_filter($games, fn (array $game) => $this->isGroupGame($game, $groupNumber, $groupByTeam))),
                    $cards,
                    $tiebreakers,
                ),
            ], range(1, $groupsCount));
        }

        return [
            'result' => ['standings' => $standings, 'groups' => $groups, 'tiebreakers' => $tiebreakers],
            'seeding' => $this->seedingFromGroups($groups, $this->cardTotals($games, $cards), $tiebreakers),
        ];
    }

    private function seedingFromGroups(array $groups, array $cards, array $tiebreakers): array
    {
        $byPosition = [];

        foreach ($groups as $group) {
            foreach (array_values($group['standings']) as $index => $row) {
                $byPosition[$index + 1][] = [...$row, 'group_number' => $group['group_number'], 'position' => $index + 1];
            }
        }

        ksort($byPosition);
        $criteria = array_values(array_diff($tiebreakers, ['head_to_head']));
        $seeding = [];

        foreach ($byPosition as $rows) {
            foreach ($this->sorter->sort($rows, $criteria, [], $cards) as $row) {
                $seeding[] = [
                    'team_id' => (int) $row['team_id'],
                    'team' => $row['team'],
                    'group_number' => $row['group_number'],
                    'position' => $row['position'],
                    'seed' => count($seeding) + 1,
                ];
            }
        }

        return $seeding;
    }

    private function cardTotals(array $games, array $cardRows): array
    {
        $gameIds = array_flip(array_column($games, 'id'));
        $cards = [];

        foreach ($cardRows as $cardRow) {
            if (isset($gameIds[(int) $cardRow->game_id])) {
                $teamId = (int) $cardRow->team_id;
                $cards[$teamId][$cardRow->card] = ($cards[$teamId][$cardRow->card] ?? 0) + (int) $cardRow->total;
            }
        }

        return $cards;
    }

    private function isGroupGame(array $game, int $groupNumber, array $groupByTeam): bool
    {
        if ($game['group_number'] !== null) {
            return $game['group_number'] === $groupNumber;
        }

        return ($groupByTeam[$game['home_team_id']] ?? null) === $groupNumber
            && ($groupByTeam[$game['away_team_id']] ?? null) === $groupNumber;
    }

    private function table(array $teams, array $games, array $cardRows, array $tiebreakers): array
    {
        $finished = array_values(array_filter($games, fn (array $game) => $game['status'] === 'finished'
            && $game['home_score'] !== null
            && $game['away_score'] !== null));

        $stats = [];
        foreach ($finished as $game) {
            $this->addResult($stats, $game['home_team_id'], $game['home_score'], $game['away_score']);
            $this->addResult($stats, $game['away_team_id'], $game['away_score'], $game['home_score']);
        }

        $cards = $this->cardTotals($games, $cardRows);

        $rows = array_map(function (Team $team) use ($stats) {
            $row = $stats[(int) $team->id] ?? [];
            $goalsFor = $row['goals_for'] ?? 0;
            $goalsAgainst = $row['goals_against'] ?? 0;
            $wins = $row['wins'] ?? 0;
            $draws = $row['draws'] ?? 0;

            return [
                'team_id' => $team->id,
                'team' => ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logoUrl()],
                'played' => $row['played'] ?? 0,
                'wins' => $wins,
                'draws' => $draws,
                'losses' => $row['losses'] ?? 0,
                'goals_for' => $goalsFor,
                'goals_against' => $goalsAgainst,
                'goal_difference' => $goalsFor - $goalsAgainst,
                'points' => $wins * 3 + $draws,
            ];
        }, $teams);

        return $this->sorter->sort($rows, $tiebreakers, $finished, $cards);
    }

    private function addResult(array &$stats, int $teamId, int $scored, int $conceded): void
    {
        $row = $stats[$teamId] ?? ['played' => 0, 'wins' => 0, 'draws' => 0, 'losses' => 0, 'goals_for' => 0, 'goals_against' => 0];

        $row['played']++;
        $row['goals_for'] += $scored;
        $row['goals_against'] += $conceded;
        $row[match ($scored <=> $conceded) {
            1 => 'wins',
            0 => 'draws',
            -1 => 'losses',
        }]++;

        $stats[$teamId] = $row;
    }

    private function phases(League $league, array $seeds): array
    {
        $games = $this->repository->knockoutGames((int) $league->id);
        $sequence = $league->usesDraw() ? ($league->knockout_tiebreakers ?? []) : [];

        $phases = $games
            ->groupBy(fn (Game $game) => ($game->phase_order ?? 'null').'|'.$game->phase)
            ->map(function (Collection $phaseGames) use ($sequence, $seeds) {
                $first = $phaseGames->first();

                return [
                    'phase' => $first->phase,
                    'phase_order' => $first->phase_order,
                    'stage' => $first->knockout_stage,
                    'ties' => $phaseGames
                        ->groupBy(fn (Game $game) => $game->tie_key ?? ('game-'.$game->id))
                        ->map(fn (Collection $tieGames) => $this->buildTie($tieGames, $sequence, $seeds))
                        ->values()
                        ->all(),
                ];
            })
            ->values();

        return $phases
            ->sortBy(fn (array $phase) => $phase['phase_order'] ?? PHP_INT_MAX)
            ->values()
            ->all();
    }

    private function buildTie(Collection $tieGames, array $sequence, array $seeds): array
    {
        $legs = $tieGames->sortBy('leg')->values();
        $generated = $legs->every(fn (Game $game) => $game->isGeneratedKnockout());

        $teamIds = $legs
            ->flatMap(fn (Game $game) => [$game->home_team_id, $game->away_team_id])
            ->unique()
            ->values();

        $teamSummaries = [];
        foreach ($legs as $game) {
            $teamSummaries[$game->home_team_id] ??= $this->teamSummary($game->homeTeam, $game->home_team_id);
            $teamSummaries[$game->away_team_id] ??= $this->teamSummary($game->awayTeam, $game->away_team_id);
        }

        $resolution = $generated
            ? $this->tieResolver->resolve($legs->map(fn (Game $game) => $this->tieResolver->legFromGame($game))->all(), $sequence, $seeds)
            : $this->manualTieResolution($legs, $teamIds);

        return [
            'tie_key' => $legs->first()->tie_key,
            'decided' => $resolution['finished'],
            'winner_team_id' => $resolution['winner_team_id'],
            'decided_by' => $resolution['decided_by'],
            'awaiting_penalties' => $resolution['awaiting_penalties'],
            'decisive_game_id' => $resolution['decisive_game_id'],
            'home_penalties' => $resolution['home_penalties'],
            'away_penalties' => $resolution['away_penalties'],
            'teams' => $teamIds->map(fn ($teamId) => array_merge(
                $teamSummaries[$teamId],
                ['aggregate_score' => $resolution['aggregate'][$teamId] ?? 0]
            ))->values()->all(),
            'legs' => $legs->map(fn (Game $game) => [
                'id' => $game->id,
                'leg' => $game->leg,
                'home_team_id' => $game->home_team_id,
                'away_team_id' => $game->away_team_id,
                'home_score' => $game->home_score,
                'away_score' => $game->away_score,
                'home_penalties' => $game->home_penalties,
                'away_penalties' => $game->away_penalties,
                'status' => $game->status,
                'match_date' => $game->match_date,
            ])->values()->all(),
        ];
    }

    private function manualTieResolution(Collection $legs, Collection $teamIds): array
    {
        $isTwoLegged = $legs->contains(fn (Game $game) => $game->leg !== null);
        $decided = $isTwoLegged
            ? collect([1, 2])->every(fn (int $leg) => $legs->contains(
                fn (Game $game) => (int) $game->leg === $leg && $this->isLegFinished($game)
            ))
            : $legs->every(fn (Game $game) => $this->isLegFinished($game));

        $aggregate = $teamIds->mapWithKeys(fn ($teamId) => [$teamId => 0])->all();

        foreach ($legs as $game) {
            if (! $this->isLegFinished($game)) {
                continue;
            }

            $aggregate[$game->home_team_id] += $game->home_score;
            $aggregate[$game->away_team_id] += $game->away_score;
        }

        $winnerId = null;
        if ($decided && $teamIds->count() === 2) {
            [$teamAId, $teamBId] = $teamIds->all();
            if ($aggregate[$teamAId] !== $aggregate[$teamBId]) {
                $winnerId = $aggregate[$teamAId] > $aggregate[$teamBId] ? $teamAId : $teamBId;
            }
        }

        return [
            'finished' => $decided,
            'winner_team_id' => $winnerId,
            'decided_by' => $winnerId === null ? null : 'aggregate',
            'awaiting_penalties' => false,
            'decisive_game_id' => null,
            'home_penalties' => null,
            'away_penalties' => null,
            'aggregate' => $aggregate,
        ];
    }

    private function isLegFinished(Game $game): bool
    {
        return $game->status === 'finished' && $game->home_score !== null && $game->away_score !== null;
    }

    private function teamSummary(?Team $team, int $teamId): array
    {
        return $team
            ? ['team_id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname]
            : ['team_id' => $teamId, 'name' => null, 'nickname' => null];
    }
}

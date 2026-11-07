<?php

namespace App\Services;

use App\Models\League;
use App\Models\Team;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeagueGroupService
{
    public function __construct(private readonly LeagueRepository $repository) {}

    public function build(League $league): array
    {
        return $this->format(
            $league,
            $this->repository->groupTeams($league),
            $this->repository->hasDrawnRounds($league),
        );
    }

    public function assign(string $id, array $assignments): array
    {
        return DB::transaction(function () use ($id, $assignments) {
            $league = $this->lockLeague($id);
            $teamIds = $this->ensureGroupsCanBeAssembled($league);

            $groupByTeam = [];
            foreach ($assignments as $assignment) {
                $groupByTeam[(int) $assignment['team_id']] = (int) $assignment['group_number'];
            }

            $this->ensureAssignmentIsValid($league, $teamIds, $groupByTeam);
            $this->repository->assignGroups($league, $groupByTeam);

            return $this->format($league, $this->repository->groupTeams($league), false);
        });
    }

    public function shuffle(string $id): array
    {
        return DB::transaction(function () use ($id) {
            $league = $this->lockLeague($id);
            $teamIds = $this->ensureGroupsCanBeAssembled($league);

            $this->repository->assignGroups($league, $this->distribute($teamIds, $league->groups_count));

            return $this->format($league, $this->repository->groupTeams($league), false);
        });
    }

    public function distribute(array $teamIds, int $groupsCount): array
    {
        $teamIds = array_values($teamIds);

        for ($i = count($teamIds) - 1; $i > 0; $i--) {
            $j = random_int(0, $i);
            [$teamIds[$i], $teamIds[$j]] = [$teamIds[$j], $teamIds[$i]];
        }

        $groupSize = intdiv(count($teamIds), $groupsCount);
        $groupByTeam = [];

        foreach ($teamIds as $index => $teamId) {
            $groupByTeam[$teamId] = intdiv($index, $groupSize) + 1;
        }

        return $groupByTeam;
    }

    private function lockLeague(string $id): League
    {
        $league = $this->repository->findForUpdate($id);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return $league;
    }

    private function ensureGroupsCanBeAssembled(League $league): array
    {
        if (! $league->usesDraw()) {
            throw ValidationException::withMessages(['message' => 'A montagem dos grupos só está disponível em ligas com sorteio.']);
        }

        if ($this->repository->hasDrawnRounds($league)) {
            throw ValidationException::withMessages(['message' => 'Os grupos não podem ser alterados porque a liga já tem rodadas sorteadas.']);
        }

        $teamIds = $this->repository->teamIds($league);
        $teamsCount = count($teamIds);

        if ($teamsCount === 0) {
            throw ValidationException::withMessages(['message' => 'A liga não tem times para distribuir nos grupos.']);
        }

        if ($teamsCount % $league->groups_count !== 0) {
            throw ValidationException::withMessages([
                'message' => sprintf(
                    'A liga tem %d times, que não podem ser divididos igualmente em %d grupos.',
                    $teamsCount,
                    $league->groups_count,
                ),
            ]);
        }

        $groupSize = intdiv($teamsCount, $league->groups_count);

        if ($groupSize < 2) {
            throw ValidationException::withMessages(['message' => 'Cada grupo precisa ter pelo menos 2 times.']);
        }

        if ($league->qualifiers_per_group !== null && $league->qualifiers_per_group >= $groupSize) {
            throw ValidationException::withMessages([
                'message' => sprintf(
                    'Os classificados por grupo devem ser menos que os %d times de cada grupo.',
                    $groupSize,
                ),
            ]);
        }

        return $teamIds;
    }

    private function ensureAssignmentIsValid(League $league, array $teamIds, array $groupByTeam): void
    {
        if (array_diff(array_keys($groupByTeam), $teamIds)) {
            throw ValidationException::withMessages(['message' => 'Um ou mais times informados não pertencem à liga.']);
        }

        if (count($groupByTeam) !== count($teamIds)) {
            throw ValidationException::withMessages(['message' => 'Todos os times da liga devem ser atribuídos a um grupo.']);
        }

        foreach ($groupByTeam as $groupNumber) {
            if ($groupNumber < 1 || $groupNumber > $league->groups_count) {
                throw ValidationException::withMessages([
                    'message' => sprintf('O número do grupo deve estar entre 1 e %d.', $league->groups_count),
                ]);
            }
        }

        $groupSize = intdiv(count($teamIds), $league->groups_count);
        $sizes = array_count_values($groupByTeam);

        for ($groupNumber = 1; $groupNumber <= $league->groups_count; $groupNumber++) {
            if (($sizes[$groupNumber] ?? 0) !== $groupSize) {
                throw ValidationException::withMessages([
                    'message' => sprintf('Todos os grupos devem ter o mesmo número de times (%d por grupo).', $groupSize),
                ]);
            }
        }
    }

    private function format(League $league, Collection $teams, bool $locked): array
    {
        $groups = [];
        for ($groupNumber = 1; $groupNumber <= $league->groups_count; $groupNumber++) {
            $groups[$groupNumber] = ['group_number' => $groupNumber, 'teams' => []];
        }

        $unassigned = [];

        foreach ($teams as $team) {
            $groupNumber = $league->resolvedGroupNumber($team->pivot->group_number);

            if ($groupNumber !== null && isset($groups[$groupNumber])) {
                $groups[$groupNumber]['teams'][] = $this->teamSummary($team);
            } else {
                $unassigned[] = $this->teamSummary($team);
            }
        }

        return [
            'league_id' => $league->id,
            'fixture_mode' => $league->fixture_mode,
            'groups_count' => $league->groups_count,
            'locked' => $locked,
            'groups' => array_values($groups),
            'unassigned' => $unassigned,
        ];
    }

    private function teamSummary(Team $team): array
    {
        return ['id' => $team->id, 'name' => $team->name, 'nickname' => $team->nickname, 'logo' => $team->logoUrl()];
    }
}

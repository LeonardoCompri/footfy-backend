<?php

namespace App\Services;

use App\Models\League;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeagueService
{
    public function __construct(private readonly LeagueRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null, ['championship']);
        }

        return $this->repository->index($user->leagueIds(), ['championship']);
    }

    public function publicIndex(): Collection
    {
        return $this->repository->index(null, ['championship']);
    }

    public function store(array $data): League
    {
        return $this->repository->store($data);
    }

    public function show(string $id): League
    {
        $league = $this->repository->show($id);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return $league;
    }

    public function publicShow(string $id): League
    {
        $league = $this->repository->show($id, ['championship', 'teams', 'games', 'stadiums']);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return $league;
    }

    public function update(array $data, string $id): League
    {
        $league = $this->show($id);
        $league->fill($data);

        $this->save($league);

        return $league;
    }

    public function save(League $league): void
    {
        DB::transaction(function () use ($league) {
            $this->ensureConfigurationIsEditable($league);

            $groupsCountChanged = $league->isDirty('groups_count');

            $this->repository->update($league);

            if ($groupsCountChanged) {
                $this->repository->clearGroups($league);
            }
        });
    }

    public function ensureConfigurationIsEditable(League $league): void
    {
        if ($league->isDirty(League::STRUCTURE_FIELDS) && $this->repository->hasDrawnRounds($league)) {
            throw ValidationException::withMessages([
                'message' => 'O formato, o modo de tabela, os grupos e os turnos da liga não podem ser alterados porque já existem rodadas sorteadas.',
            ]);
        }

        if (
            $league->getOriginal('fixture_mode') === 'draw'
            && $league->isDirty([...League::KNOCKOUT_FIELDS, ...League::CRITERIA_FIELDS])
            && $this->repository->hasKnockoutGames($league)
        ) {
            throw ValidationException::withMessages([
                'message' => 'O mata-mata e os critérios de desempate da liga não podem ser alterados porque o mata-mata já foi gerado.',
            ]);
        }
    }

    public function destroy(string $id): ?bool
    {
        $league = $this->show($id);

        return $this->repository->destroy($league);
    }
}

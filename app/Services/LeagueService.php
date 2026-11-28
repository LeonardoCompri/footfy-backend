<?php

namespace App\Services;

use App\Models\League;
use App\Repositories\LeagueRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class LeagueService
{
    public function __construct(
        private readonly LeagueRepository $repository,
        private readonly AuditLogger $auditLogger,
        private readonly LeagueCacheVersion $cacheVersion,
    ) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null, ['championship']);
        }

        return $this->repository->index($user->leagueIds(), ['championship']);
    }

    public function store(array $data): League
    {
        return DB::transaction(function () use ($data) {
            $league = $this->repository->store($data);
            $this->auditLogger->record('league.created', $league, new: $league->auditAttributes());

            return $league;
        });
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
        return $this->cacheVersion->remember($this->cacheVersion->key((int) $id, 'public_show'), function () use ($id) {
            $league = $this->repository->show($id, ['championship', 'teams', 'stadiums'], ['teams', 'games', 'stadiums']);

            if (! $league) {
                throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
            }

            return $league;
        });
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

            $clearGroups = $league->isDirty('groups_count')
                || ($league->isDirty('format') && $league->format === 'knockout')
                || ($league->isDirty('fixture_mode') && $league->fixture_mode === 'manual');

            $changes = $league->auditChanges();
            $this->repository->update($league);

            if ($clearGroups) {
                $this->repository->clearGroups($league);
            }

            if ($changes['new'] !== []) {
                $this->auditLogger->record(
                    'league.updated',
                    $league,
                    $changes['old'],
                    $changes['new'],
                    $clearGroups ? ['groups_cleared' => true] : [],
                );
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
        $league = $this->repository->show($id, [], ['teams', 'games', 'stadiums']);

        if (! $league) {
            throw ValidationException::withMessages(['message' => 'Liga não encontrada!']);
        }

        return DB::transaction(function () use ($league) {
            $deleted = $this->repository->destroy($league);

            if ($deleted) {
                $this->auditLogger->record(
                    'league.deleted',
                    $league,
                    old: Arr::except($league->auditAttributes(), ['teams_count', 'games_count', 'stadiums_count']),
                    meta: $league->only(['teams_count', 'games_count', 'stadiums_count']),
                );
            }

            return $deleted;
        });
    }
}

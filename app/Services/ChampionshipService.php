<?php

namespace App\Services;

use App\Models\Championship;
use App\Repositories\ChampionshipRepository;
use App\Services\Concerns\HandlesImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChampionshipService
{
    use HandlesImageUpload;

    public function __construct(
        private readonly ChampionshipRepository $repository,
        private readonly LeagueService $leagueService,
        private readonly AuditLogger $auditLogger,
        private readonly LeagueCacheVersion $cacheVersion,
    ) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function publicIndex(array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        if (! $paginate || filled($filters['search'] ?? null) || (int) ($filters['page'] ?? 1) !== 1) {
            return $this->repository->publicIndex($filters, $paginate);
        }

        $name = sprintf(
            'public_index:%d:%s:%s',
            (int) ($filters['per_page'] ?? config('footfy.pagination.per_page')),
            $filters['sort'] ?? '',
            $filters['order'] ?? '',
        );

        return $this->cacheVersion->remember(
            $this->cacheVersion->championshipsKey($name),
            fn () => $this->repository->publicIndex($filters, $paginate),
        );
    }

    public function store(array $data): Championship
    {
        $leagues = Arr::pull($data, 'leagues') ?? [];

        if (isset($data['image'])) {
            $data['image'] = $this->storeImage($data['image'], 'championships');
        }

        try {
            $championship = DB::transaction(function () use ($data, $leagues) {
                $championship = $this->repository->store($data);
                $this->auditLogger->record('championship.created', $championship, new: $championship->auditAttributes());
                $this->saveLeagues($championship, $leagues);

                return $championship;
            });
        } catch (Throwable $th) {
            $this->deleteImage($data['image'] ?? null);

            throw $th;
        }

        return $this->repository->loadLeagues($championship);
    }

    public function show(string $id): Championship
    {
        return $this->find($id, ['leagues']);
    }

    public function publicShow(string $id): Championship
    {
        return $this->cacheVersion->remember(
            $this->cacheVersion->championshipKey((int) $id, 'public_show'),
            fn () => $this->find($id, ['leagues']),
        );
    }

    public function update(array $data, string $id): Championship
    {
        $championship = $this->find($id, ['leagues']);
        $leagues = Arr::pull($data, 'leagues') ?? [];
        $oldImage = null;

        if (array_key_exists('image', $data)) {
            $oldImage = $championship->image;

            if ($data['image'] !== null) {
                $data['image'] = $this->storeImage($data['image'], 'championships');
            }
        }

        try {
            DB::transaction(function () use ($championship, $data, $leagues) {
                $championship->fill($data);
                $changes = $championship->auditChanges();
                $this->repository->update($championship);

                if ($changes['new'] !== []) {
                    $this->auditLogger->record('championship.updated', $championship, $changes['old'], $changes['new']);
                }

                $this->saveLeagues($championship, $leagues);
            });
        } catch (Throwable $th) {
            $this->deleteImage($data['image'] ?? null);

            throw $th;
        }

        $this->deleteImage($oldImage);

        return $this->repository->loadLeagues($championship);
    }

    public function destroy(string $id): ?bool
    {
        $championship = $this->find($id, ['leagues:id,championship_id,name']);

        $deleted = DB::transaction(function () use ($championship) {
            $deleted = $this->repository->destroy($championship);

            if ($deleted) {
                $this->auditLogger->record(
                    'championship.deleted',
                    $championship,
                    old: $championship->auditAttributes(),
                    meta: ['deleted_leagues' => $championship->leagues->sortBy('id')->map->only(['id', 'name'])->values()->all()],
                );
            }

            return $deleted;
        });

        if ($deleted) {
            $this->deleteImage($championship->image);
        }

        return $deleted;
    }

    private function find(string $id, array $with = []): Championship
    {
        $championship = $this->repository->show($id, $with);

        if (! $championship) {
            throw ValidationException::withMessages(['message' => 'Campeonato não encontrado!']);
        }

        return $championship;
    }

    private function saveLeagues(Championship $championship, array $leagues): void
    {
        $existing = $championship->relationLoaded('leagues') ? $championship->leagues->keyBy('id') : collect();

        foreach ($leagues as $data) {
            $leagueId = Arr::pull($data, 'id');

            if (array_key_exists('format', $data) && $data['format'] === null) {
                unset($data['format']);
            }

            $data['championship_id'] = $championship->id;

            if ($leagueId) {
                $league = $existing->get($leagueId);
                $league->fill($data);
                $this->leagueService->save($league);
            } else {
                $this->leagueService->store($data);
            }
        }
    }
}

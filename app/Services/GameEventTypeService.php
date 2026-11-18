<?php

namespace App\Services;

use App\Models\GameEventType;
use App\Repositories\GameEventTypeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameEventTypeService
{
    private const IN_USE_MESSAGE = 'Tipo em uso; inative-o.';

    public function __construct(
        private readonly GameEventTypeRepository $repository,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): GameEventType
    {
        $data['sort_order'] ??= $this->repository->maxSortOrder() + 1;

        return DB::transaction(function () use ($data) {
            $type = $this->repository->store($data);
            $this->auditLogger->record('game_event_type.created', $type, new: $type->auditAttributes());

            return $type;
        });
    }

    public function show(string $id): GameEventType
    {
        $type = $this->repository->show($id);

        if (! $type) {
            throw ValidationException::withMessages(['message' => 'Tipo de evento não encontrado!']);
        }

        return $type;
    }

    public function update(array $data, string $id): GameEventType
    {
        $type = $this->show($id);

        $type->fill($data);

        if ($type->isDirty(GameEventType::BEHAVIOR_FIELDS) && $this->repository->hasEvents($type)) {
            throw ValidationException::withMessages([
                'message' => 'Tipo em uso: não é possível alterar se ele conta no placar, se permite assistência nem o cartão.',
            ]);
        }

        DB::transaction(function () use ($type) {
            $changes = $type->auditChanges();
            $this->repository->update($type);

            if ($changes['new'] !== []) {
                $this->auditLogger->record('game_event_type.updated', $type, $changes['old'], $changes['new']);
            }
        });

        return $type;
    }

    public function destroy(string $id): ?bool
    {
        $type = $this->show($id);

        if ($this->repository->hasEvents($type)) {
            throw ValidationException::withMessages(['message' => self::IN_USE_MESSAGE]);
        }

        try {
            return DB::transaction(function () use ($type) {
                $deleted = $this->repository->destroy($type);

                if ($deleted) {
                    $this->auditLogger->record('game_event_type.deleted', $type, old: $type->auditAttributes());
                }

                return $deleted;
            });
        } catch (QueryException) {
            throw ValidationException::withMessages(['message' => self::IN_USE_MESSAGE]);
        }
    }
}

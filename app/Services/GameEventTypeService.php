<?php

namespace App\Services;

use App\Models\GameEventType;
use App\Repositories\GameEventTypeRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Database\QueryException;
use Illuminate\Validation\ValidationException;

class GameEventTypeService
{
    private const IN_USE_MESSAGE = 'Tipo em uso; inative-o.';

    public function __construct(private readonly GameEventTypeRepository $repository) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function store(array $data): GameEventType
    {
        $data['sort_order'] ??= $this->repository->maxSortOrder() + 1;

        return $this->repository->store($data);
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

        $this->repository->update($type);

        return $type;
    }

    public function destroy(string $id): ?bool
    {
        $type = $this->show($id);

        if ($this->repository->hasEvents($type)) {
            throw ValidationException::withMessages(['message' => self::IN_USE_MESSAGE]);
        }

        try {
            return $this->repository->destroy($type);
        } catch (QueryException) {
            throw ValidationException::withMessages(['message' => self::IN_USE_MESSAGE]);
        }
    }
}

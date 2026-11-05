<?php

namespace App\Services;

use App\Models\Championship;
use App\Repositories\ChampionshipRepository;
use App\Repositories\LeagueRepository;
use App\Services\Concerns\HandlesImageUpload;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

class ChampionshipService
{
    use HandlesImageUpload;

    public function __construct(
        private readonly ChampionshipRepository $repository,
        private readonly LeagueRepository $leagueRepository,
    ) {}

    public function index(): Collection
    {
        return $this->repository->index();
    }

    public function publicIndex(): Collection
    {
        return $this->repository->index();
    }

    /**
     * Cria o campeonato e as ligas de `leagues` (opcional) na mesma transação. A imagem é
     * gravada antes; se a transação falhar, o arquivo novo é removido para não ficar órfão.
     */
    public function store(array $data): Championship
    {
        $leagues = Arr::pull($data, 'leagues') ?? [];

        if (isset($data['image'])) {
            $data['image'] = $this->storeImage($data['image'], 'championships');
        }

        try {
            $championship = DB::transaction(function () use ($data, $leagues) {
                $championship = $this->repository->store($data);
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
        return $this->find($id, ['leagues']);
    }

    /**
     * Atualiza o campeonato e grava `leagues` na mesma transação: liga com id é atualizada,
     * sem id é criada e a que não veio no payload fica intacta (nada é apagado aqui).
     * A imagem antiga só é removida depois do commit.
     */
    public function update(array $data, string $id): Championship
    {
        $championship = $this->find($id, ['leagues']);
        $leagues = Arr::pull($data, 'leagues') ?? [];
        $oldImage = null;

        if (isset($data['image'])) {
            $oldImage = $championship->image;
            $data['image'] = $this->storeImage($data['image'], 'championships');
        }

        try {
            DB::transaction(function () use ($championship, $data, $leagues) {
                $championship->fill($data);
                $this->repository->update($championship);
                $this->saveLeagues($championship, $leagues);
            });
        } catch (Throwable $th) {
            $this->deleteImage($data['image'] ?? null);

            throw $th;
        }

        $this->deleteImage($oldImage);

        return $this->repository->loadLeagues($championship);
    }

    // Apaga em cascata as ligas (e partidas, eventos, estádios e vínculos delas); times,
    // jogadores e usuários ficam. A imagem só sai do disco depois que o registro foi apagado.
    public function destroy(string $id): ?bool
    {
        $championship = $this->find($id);
        $deleted = $this->repository->destroy($championship);

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

    /**
     * Cria (sem id) ou atualiza (com id) as ligas do campeonato. O FormRequest já garante que
     * o id pertence a este campeonato; `championship_id` sempre vem do servidor e `format`
     * nulo não sobrescreve nada (na criação vale o default do model).
     */
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
                $this->leagueRepository->update($league);
            } else {
                $this->leagueRepository->store($data);
            }
        }
    }
}

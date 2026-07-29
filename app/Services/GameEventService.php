<?php

namespace App\Services;

use App\Models\Game;
use App\Models\GameEvent;
use App\Repositories\GameEventRepository;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class GameEventService
{
    public function __construct(private readonly GameEventRepository $repository) {}

    public function index(?string $type = null): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null, $type);
        }
        $leagueIds = $user->leagueIds();
        $gameIds = Game::whereIn('league_id', $leagueIds)
            ->pluck('id')
            ->toArray();

        return $this->repository->index($gameIds, $type);
    }

    /**
     * Preenche league_id automaticamente a partir do jogo informado — esse
     * campo não vem do payload do cliente, para não correr risco de
     * divergência por entrada manual.
     */
    public function store(array $data): GameEvent
    {
        $game = Game::find($data['game_id']);
        $data['league_id'] = $game->league_id;

        return $this->repository->store($data);
    }

    public function show(string $id): GameEvent
    {
        $event = $this->repository->show($id);

        if (! $event) {
            throw ValidationException::withMessages(['message' => 'Evento não encontrado!']);
        }

        return $event;
    }

    /**
     * Se game_id mudar, league_id é recalculado a partir do novo jogo, pelo
     * mesmo motivo do store: nunca aceitar league_id vindo do cliente.
     */
    public function update(array $data, string $id): GameEvent
    {
        $event = $this->show($id);

        if (isset($data['game_id'])) {
            $game = Game::find($data['game_id']);
            $data['league_id'] = $game->league_id;
        }

        $event->fill($data);

        $this->repository->update($event);

        return $event;
    }

    public function destroy(string $id): ?bool
    {
        $event = $this->show($id);

        return $this->repository->destroy($event);
    }
}

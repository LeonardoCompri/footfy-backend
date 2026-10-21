<?php

namespace App\Services;

use App\Models\Game;
use App\Repositories\GameRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class GameService
{
    use EnsuresLeagueAccess;

    public function __construct(private readonly GameRepository $repository) {}

    public function index(string $leagueId, array $filters = [], bool $paginate = false): Collection|LengthAwarePaginator
    {
        $user = Auth::user();
        if(!$user->isMaster() && !$user->hasLeagueId($leagueId)) {            
            throw ValidationException::withMessages(['message' => 'Você não tem acesso a essa liga.']);
        }

        return $this->repository->index($leagueId, $filters, $paginate);
    }

    public function store(array $data): Game
    {
        $this->ensureUserHasAccessToLeagues([$data['league_id']]);

        return $this->repository->store($data);
    }

    public function show(string $id): Game
    {
        $game = $this->repository->show($id, ['homeTeam', 'awayTeam', 'stadium', 'events']);

        if (! $game) {
            throw ValidationException::withMessages(['message' => 'Partida não encontrada!']);
        }

        return $game;
    }

    public function publicShow(string $id): Game
    {
        $game = $this->repository->show($id, ['homeTeam', 'awayTeam', 'stadium', 'events']);

        if (! $game) {
            throw ValidationException::withMessages(['message' => 'Partida não encontrada!']);
        }

        return $game;
    }

    public function update(array $data, string $id): Game
    {
        $game = $this->show($id);

        if (array_key_exists('league_id', $data)) {
            $this->ensureUserHasAccessToLeagues([$data['league_id']]);
        }

        $game->fill($data);
        $leagueChanged = $game->isDirty('league_id');

        // game_events.league_id é denormalizado: ao trocar a liga da partida, os eventos acompanham.
        DB::transaction(function () use ($game, $leagueChanged) {
            $this->repository->update($game);

            if ($leagueChanged) {
                $this->repository->syncEventsLeague($game);
            }
        });

        // show() já carregou `events`; recarrega para a resposta não sair com o league_id antigo.
        return $leagueChanged ? $game->load('events') : $game;
    }

    public function destroy(string $id): ?bool
    {
        $game = $this->show($id);

        return $this->repository->destroy($game);
    }
}

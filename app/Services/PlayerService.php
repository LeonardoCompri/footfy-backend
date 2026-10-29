<?php

namespace App\Services;

use App\Models\Player;
use App\Models\Team;
use App\Repositories\PlayerRepository;
use App\Services\Concerns\EnsuresLeagueAccess;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\ValidationException;

class PlayerService
{
    use EnsuresLeagueAccess;

    public function __construct(private readonly PlayerRepository $repository) {}

    public function index(): Collection
    {
        $user = Auth::user();
        if ($user->isMaster()) {
            return $this->repository->index(null);
        }
        $leagueIds = $user->leagueIds();
        $teamIds = Team::whereHas('leagues', fn ($q) => $q->whereIn('leagues.id', $leagueIds))
            ->pluck('id')
            ->toArray();

        return $this->repository->index($teamIds);
    }

    /**
     * O unique de `document` no banco é global (inclui removidos): recadastrar o CPF de um
     * jogador removido (soft delete) restaura esse mesmo registro (mesmo id, súmula preservada)
     * com os dados novos, em vez de criar outro. Os campos opcionais que não vierem no payload
     * são zerados, para não herdar dados do cadastro antigo. Restaura mesmo que o removido fosse
     * de time de outra liga: o acesso conferido é só ao time de destino.
     */
    public function store(array $data): Player
    {
        $this->ensureUserCanWriteToTeam($data['team_id']);

        $trashed = ! empty($data['document']) ? $this->repository->findTrashedByDocument($data['document']) : null;

        if ($trashed) {
            return $this->repository->restore($trashed, array_merge(
                ['position' => null, 'number' => null, 'birth_date' => null],
                $data,
            ));
        }

        return $this->repository->store($data);
    }

    // Lista de opções sugeridas para o select de posição no admin (Player::POSITIONS).
    public function positions(): array
    {
        return Player::POSITIONS;
    }

    public function show(string $id): Player
    {
        $player = $this->repository->show($id);

        if (! $player) {
            throw ValidationException::withMessages(['message' => 'Jogador não encontrado!']);
        }

        return $player;
    }

    public function update(array $data, string $id): Player
    {
        $player = $this->show($id);

        if (array_key_exists('team_id', $data)) {
            // Coach não transfere jogador: o time só pode continuar o mesmo.
            if (Auth::user()->isCoach() && (int) $data['team_id'] !== (int) $player->team_id) {
                throw ValidationException::withMessages(['message' => 'Você não pode transferir o jogador para outro time.']);
            }

            $this->ensureUserCanWriteToTeam($data['team_id']);
        }

        $player->fill($data);

        $this->repository->update($player);

        return $player;
    }

    public function destroy(string $id): ?bool
    {
        $player = $this->show($id);

        return $this->repository->destroy($player);
    }

    // Coach só escreve no próprio time (users.team_id); demais papéis pela liga do time.
    private function ensureUserCanWriteToTeam(int|string $teamId): void
    {
        $user = Auth::user();

        if ($user->isCoach()) {
            if ($user->team_id === null || (int) $teamId !== (int) $user->team_id) {
                throw ValidationException::withMessages(['message' => 'Você só pode gerenciar jogadores do seu time.']);
            }

            return;
        }

        $this->ensureUserHasAccessToTeam($teamId);
    }
}

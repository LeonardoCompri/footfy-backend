<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\GameEvent;
use App\Models\Player;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;
use Illuminate\Validation\Rules\Exists;

class GameEventUpdateRequest extends FormRequest
{
    private ?GameEvent $gameEvent = null;

    public function rules(): array
    {
        $event = $this->event();

        return [
            'game_id' => ['sometimes', 'integer', 'exists:games,id'],
            'player_id' => ['sometimes', 'integer', $this->activePlayerOrCurrent($event?->player_id)],
            'team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'related_player_id' => ['nullable', 'integer', $this->activePlayerOrCurrent($event?->related_player_id)],
            'type' => ['sometimes', 'string', 'in:goal,yellow_card,red_card'],
            'minute' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function messages(): array
    {
        return [
            'player_id.exists' => 'O jogador informado foi removido ou não existe.',
            'related_player_id.exists' => 'O jogador informado foi removido ou não existe.',
        ];
    }

    // Evento da rota (null se não existir: o Service responde "Evento não encontrado!").
    private function event(): ?GameEvent
    {
        return $this->gameEvent ??= ($this->route('id') ? GameEvent::find($this->route('id')) : null);
    }

    /**
     * Jogador ativo, ou o já gravado no evento mesmo que removido (soft delete): permite
     * editar outros campos do evento sem trocar o jogador removido, mas não escolher outro removido.
     */
    private function activePlayerOrCurrent(?int $currentPlayerId): Exists
    {
        return Rule::exists('players', 'id')->where(
            fn ($query) => $query->whereNull('deleted_at')
                ->when($currentPlayerId, fn ($query, $id) => $query->orWhere('id', $id))
        );
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            // after() roda mesmo com regra falhando: com campo[]=1, find(array) devolveria Collection (500).
            if ($validator->errors()->hasAny(['game_id', 'player_id', 'team_id', 'related_player_id'])) {
                return;
            }

            $event = $this->event();

            $gameId = $this->input('game_id', $event?->game_id);
            $playerId = $this->input('player_id', $event?->player_id);
            $teamId = $this->input('team_id', $event?->team_id);
            $relatedPlayerId = $this->input('related_player_id', $event?->related_player_id);

            if (! $gameId || ! $playerId || ! $teamId) {
                return;
            }

            $game = Game::find($gameId);
            if ($game && ! in_array((int) $teamId, [$game->home_team_id, $game->away_team_id])) {
                $validator->errors()->add('team_id', 'O time informado não participa desta partida.');

                return;
            }

            $player = Player::withTrashed()->find($playerId);
            if ($player && (int) $player->team_id !== (int) $teamId) {
                $validator->errors()->add('player_id', 'O jogador informado não pertence ao time informado.');
            }

            if (! $relatedPlayerId) {
                return;
            }

            if ((int) $relatedPlayerId === (int) $playerId) {
                $validator->errors()->add('related_player_id', 'O jogador relacionado deve ser diferente do jogador principal.');

                return;
            }

            $relatedPlayer = Player::withTrashed()->find($relatedPlayerId);
            if ($relatedPlayer && (int) $relatedPlayer->team_id !== (int) $teamId) {
                $validator->errors()->add('related_player_id', 'O jogador relacionado não pertence ao time informado.');
            }
        });
    }
}

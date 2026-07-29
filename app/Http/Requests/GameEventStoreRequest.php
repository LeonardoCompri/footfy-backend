<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\Player;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GameEventStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id' => ['required', 'integer', 'exists:games,id'],
            'player_id' => ['required', 'integer', 'exists:players,id'],
            'team_id' => ['required', 'integer', 'exists:teams,id'],
            'related_player_id' => ['nullable', 'integer', 'exists:players,id'],
            'type' => ['required', 'string', 'in:goal,yellow_card,red_card'],
            'minute' => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $gameId = $this->input('game_id');
            $playerId = $this->input('player_id');
            $teamId = $this->input('team_id');
            $relatedPlayerId = $this->input('related_player_id');

            if (! $gameId || ! $playerId || ! $teamId) {
                return;
            }

            $game = Game::find($gameId);
            if ($game && ! in_array((int) $teamId, [$game->home_team_id, $game->away_team_id])) {
                $validator->errors()->add('team_id', 'O time informado não participa desta partida.');

                return;
            }

            $player = Player::find($playerId);
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

            $relatedPlayer = Player::find($relatedPlayerId);
            if ($relatedPlayer && (int) $relatedPlayer->team_id !== (int) $teamId) {
                $validator->errors()->add('related_player_id', 'O jogador relacionado não pertence ao time informado.');
            }
        });
    }
}

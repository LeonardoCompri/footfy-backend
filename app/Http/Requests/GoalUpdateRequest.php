<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\Goal;
use App\Models\Player;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GoalUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'game_id'   => ['sometimes', 'integer', 'exists:games,id'],
            'player_id' => ['sometimes', 'integer', 'exists:players,id'],
            'team_id'   => ['sometimes', 'integer', 'exists:teams,id'],
            'minute'    => ['nullable', 'integer', 'min:0'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $goal = $this->route('id') ? Goal::find($this->route('id')) : null;

            $gameId = $this->input('game_id', $goal?->game_id);
            $playerId = $this->input('player_id', $goal?->player_id);
            $teamId = $this->input('team_id', $goal?->team_id);

            if (!$gameId || !$playerId || !$teamId) {
                return;
            }

            $game = Game::find($gameId);
            if ($game && !in_array((int) $teamId, [$game->home_team_id, $game->away_team_id])) {
                $validator->errors()->add('team_id', 'O time informado não participa desta partida.');
                return;
            }

            $player = Player::find($playerId);
            if ($player && (int) $player->team_id !== (int) $teamId) {
                $validator->errors()->add('player_id', 'O jogador informado não pertence ao time informado.');
            }
        });
    }
}

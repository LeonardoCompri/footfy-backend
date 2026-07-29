<?php

namespace App\Http\Requests;

use App\Models\Game;
use App\Models\Stadium;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GameUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'league_id' => ['sometimes', 'integer', 'exists:leagues,id'],
            'home_team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'away_team_id' => ['sometimes', 'integer', 'exists:teams,id', 'different:home_team_id'],
            'stadium_id' => ['nullable', 'integer', 'exists:stadiums,id'],
            'match_date' => ['nullable', 'date'],
            'home_score' => ['nullable', 'integer', 'min:0'],
            'away_score' => ['nullable', 'integer', 'min:0'],
            'round' => ['nullable', 'integer', 'min:1'],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,finished,cancelled'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $game = $this->route('id') ? Game::find($this->route('id')) : null;

            $leagueId = $this->input('league_id', $game?->league_id);
            $stadiumId = $this->input('stadium_id', $game?->stadium_id);

            if (! $leagueId || ! $stadiumId) {
                return;
            }

            $stadium = Stadium::find($stadiumId);
            if ($stadium && (int) $stadium->league_id !== (int) $leagueId) {
                $validator->errors()->add('stadium_id', 'O estádio informado não pertence à liga desta partida.');
            }
        });
    }
}

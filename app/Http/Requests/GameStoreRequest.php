<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGameTeams;
use App\Models\League;
use App\Models\Stadium;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GameStoreRequest extends FormRequest
{
    use ValidatesGameTeams;

    public function rules(): array
    {
        return [
            'league_id' => ['required', 'integer', 'exists:leagues,id'],
            'home_team_id' => ['required', 'integer', 'exists:teams,id'],
            'away_team_id' => ['required', 'integer', 'exists:teams,id', 'different:home_team_id'],
            'stadium_id' => ['nullable', 'integer', 'exists:stadiums,id'],
            'match_date' => ['nullable', 'date'],
            'round' => ['nullable', 'integer', 'min:1'],
            'phase' => ['nullable', 'string', 'max:255'],
            'phase_order' => ['nullable', 'integer', 'min:1'],
            'tie_key' => ['nullable', 'required_with:leg', 'string', 'max:255'],
            'leg' => ['nullable', 'integer', 'in:1,2'],
            'status' => ['required', 'string', 'in:scheduled,in_progress,finished,cancelled'],
        ];
    }

    public function messages(): array
    {
        return [
            'tie_key.required_with' => 'Informe a chave do confronto para partidas de ida e volta.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $leagueId = $this->input('league_id');
            $stadiumId = $this->input('stadium_id');

            if ($leagueId && $stadiumId) {
                $stadium = Stadium::find($stadiumId);
                if ($stadium && (int) $stadium->league_id !== (int) $leagueId) {
                    $validator->errors()->add('stadium_id', 'O estádio informado não pertence à liga desta partida.');
                }
            }

            $league = $leagueId && ! $validator->errors()->has('league_id') ? League::find($leagueId) : null;

            $this->validateTeamsBelongToLeague($validator, $league, [
                'home_team_id' => $this->input('home_team_id'),
                'away_team_id' => $this->input('away_team_id'),
            ]);
            $this->validateRoundAndPhase($validator, $league);
        });
    }

    private function validateRoundAndPhase(Validator $validator, ?League $league): void
    {
        $round = $this->input('round');
        $phase = $this->input('phase');

        if ($round !== null && $phase !== null) {
            $validator->errors()->add('phase', 'Informe apenas Rodada ou Fase, nunca os dois.');

            return;
        }

        if (! $league) {
            return;
        }

        if ($league->format === 'knockout') {
            if ($round !== null) {
                $validator->errors()->add('round', 'Partidas de uma liga mata-mata usam Fase, não Rodada.');
            } elseif ($phase === null) {
                $validator->errors()->add('phase', 'Informe a Fase desta partida (liga mata-mata).');
            }
        }

        if ($league->format === 'round_robin' && $phase !== null) {
            $validator->errors()->add('phase', 'Partidas de uma liga de pontos corridos usam Rodada, não Fase.');
        }
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDrawnGames;
use App\Http\Requests\Concerns\ValidatesGameTeams;
use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class GameUpdateRequest extends FormRequest
{
    use ValidatesDrawnGames, ValidatesGameTeams;

    public function rules(): array
    {
        return [
            'league_id' => ['sometimes', 'integer', 'exists:leagues,id'],
            'home_team_id' => ['sometimes', 'integer', 'exists:teams,id'],
            'away_team_id' => ['sometimes', 'integer', 'exists:teams,id', 'different:home_team_id'],
            'stadium_id' => ['nullable', 'integer', 'exists:stadiums,id'],
            'match_date' => ['nullable', 'date'],
            'round' => ['nullable', 'integer', 'min:1'],
            'group_number' => ['sometimes', 'nullable', 'integer'],
            'phase' => ['nullable', 'string', 'max:255'],
            'phase_order' => ['nullable', 'integer', 'min:1'],
            'tie_key' => ['nullable', 'string', 'max:255'],
            'leg' => ['nullable', 'integer', 'in:1,2'],
            'status' => ['sometimes', 'string', 'in:scheduled,in_progress,finished,cancelled'],
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $game = $this->route('id') ? Game::find($this->route('id')) : null;

            $this->rejectDrawnGameFieldChanges($validator, $game);

            $leagueId = $this->input('league_id', $game?->league_id);
            $stadiumId = $this->input('stadium_id', $game?->stadium_id);

            if ($leagueId && $stadiumId) {
                $stadium = Stadium::find($stadiumId);
                if ($stadium && (int) $stadium->league_id !== (int) $leagueId) {
                    $validator->errors()->add('stadium_id', 'O estádio informado não pertence à liga desta partida.');
                }
            }

            $league = $leagueId && ! $validator->errors()->has('league_id') ? League::find($leagueId) : null;

            $this->validateTeams($validator, $game, $league);
            $this->validateRoundAndPhase($validator, $game, $league);
            $this->validateTieKey($validator, $game);

            if ($game?->group_number === null && $this->hasAny(['round', 'league_id'])) {
                $this->rejectManualRoundInDrawLeague($validator, $league, $this->input('round', $game?->round));
            }
        });
    }

    private function validateTeams(Validator $validator, ?Game $game, ?League $league): void
    {
        if (! $this->hasAny(['league_id', 'home_team_id', 'away_team_id'])) {
            return;
        }

        $homeTeamId = $this->input('home_team_id', $game?->home_team_id);
        $awayTeamId = $this->input('away_team_id', $game?->away_team_id);

        $sameTeamField = $this->has('away_team_id') ? 'away_team_id' : 'home_team_id';
        if (
            $homeTeamId !== null && $awayTeamId !== null
            && ! $validator->errors()->hasAny(['home_team_id', 'away_team_id'])
            && (int) $homeTeamId === (int) $awayTeamId
        ) {
            $validator->errors()->add($sameTeamField, 'O time mandante e o visitante devem ser diferentes.');

            return;
        }

        $this->validateTeamsBelongToLeague($validator, $league, [
            'home_team_id' => $homeTeamId,
            'away_team_id' => $awayTeamId,
        ]);
    }

    private function validateTieKey(Validator $validator, ?Game $game): void
    {
        $leg = $this->input('leg', $game?->leg);
        $tieKey = $this->input('tie_key', $game?->tie_key);

        if ($leg !== null && blank($tieKey)) {
            $validator->errors()->add('tie_key', 'Informe a chave do confronto para partidas de ida e volta.');
        }
    }

    private function validateRoundAndPhase(Validator $validator, ?Game $game, ?League $league): void
    {
        $round = $this->input('round', $game?->round);
        $phase = $this->input('phase', $game?->phase);

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

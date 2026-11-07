<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesDrawnGames;
use App\Http\Requests\Concerns\ValidatesGameTeams;
use App\Models\Game;
use App\Models\League;
use App\Models\Stadium;
use App\Services\LeagueKnockoutService;
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
            'home_penalties' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
            'away_penalties' => ['sometimes', 'nullable', 'integer', 'min:0', 'max:99'],
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

            if ($this->hasAny(['round', 'league_id'])) {
                $this->rejectGameBecomingManualRound($validator, $league, $game);
            }

            if ($this->hasAny(['phase', 'league_id'])) {
                $this->rejectGameBecomingManualPhase($validator, $league, $game);
            }

            $this->validatePenalties($validator, $game);
        });
    }

    private function validatePenalties(Validator $validator, ?Game $game): void
    {
        if (! $game || ! $this->hasAny(['home_penalties', 'away_penalties'])) {
            return;
        }

        if ($validator->errors()->hasAny(['home_penalties', 'away_penalties', 'status'])) {
            return;
        }

        $home = $this->has('home_penalties') ? $this->input('home_penalties') : $game->home_penalties;
        $away = $this->has('away_penalties') ? $this->input('away_penalties') : $game->away_penalties;

        if ($home === null && $away === null) {
            return;
        }

        if ($home === null || $away === null) {
            $validator->errors()->add('home_penalties', 'Informe os pênaltis dos dois times.');

            return;
        }

        if ((int) $home === (int) $away) {
            $validator->errors()->add('home_penalties', 'A disputa de pênaltis não pode terminar empatada.');

            return;
        }

        if ((string) $home === (string) $game->home_penalties && (string) $away === (string) $game->away_penalties) {
            return;
        }

        $issue = app(LeagueKnockoutService::class)->penaltiesIssue($game, [
            'status' => $this->input('status', $game->status),
        ]);

        if ($issue !== null) {
            $validator->errors()->add('home_penalties', $issue);
        }
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

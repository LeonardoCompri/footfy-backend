<?php

namespace App\Http\Requests\Concerns;

use App\Models\Game;
use App\Models\League;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesDrawnGames
{
    private const DRAWN_GAME_LOCKED_FIELDS = ['league_id', 'round', 'group_number', 'phase', 'phase_order', 'tie_key', 'leg'];

    private const KNOCKOUT_GAME_LOCKED_FIELDS = ['league_id', 'home_team_id', 'away_team_id', 'round', 'group_number', 'phase', 'phase_order', 'tie_key', 'leg'];

    protected function rejectManualPhaseInDrawLeague(Validator $validator, ?League $league, mixed $phase): void
    {
        if ($league && $league->usesDraw() && $phase !== null && ! $validator->errors()->has('phase')) {
            $validator->errors()->add('phase', 'Em ligas com sorteio, as partidas de mata-mata são geradas pela classificação.');
        }
    }

    protected function rejectGameBecomingManualPhase(Validator $validator, ?League $league, ?Game $game): void
    {
        $finalPhase = $this->input('phase', $game?->phase);

        if (! $game) {
            $this->rejectManualPhaseInDrawLeague($validator, $league, $finalPhase);

            return;
        }

        if ($game->isGeneratedKnockout()) {
            return;
        }

        $finalLeagueId = $this->input('league_id', $game->league_id);
        $becomesPhase = $finalPhase !== null && (
            (int) $finalLeagueId !== (int) $game->league_id
            || $game->phase === null
        );

        if ($becomesPhase) {
            $this->rejectManualPhaseInDrawLeague($validator, $league, $finalPhase);
        }
    }

    protected function rejectManualRoundInDrawLeague(Validator $validator, ?League $league, mixed $round): void
    {
        if ($league && $league->usesDraw() && $round !== null && ! $validator->errors()->has('round')) {
            $validator->errors()->add('round', 'Em ligas com sorteio, as partidas de rodada são criadas pelo sorteio.');
        }
    }

    protected function rejectGameBecomingManualRound(Validator $validator, ?League $league, ?Game $game): void
    {
        $finalRound = $this->input('round', $game?->round);

        if (! $game) {
            $this->rejectManualRoundInDrawLeague($validator, $league, $finalRound);

            return;
        }

        if ($game->group_number !== null) {
            return;
        }

        $finalLeagueId = $this->input('league_id', $game->league_id);
        $becomesRound = $finalRound !== null && (
            (int) $finalLeagueId !== (int) $game->league_id
            || $game->round === null
        );

        if ($becomesRound) {
            $this->rejectManualRoundInDrawLeague($validator, $league, $finalRound);
        }
    }

    protected function rejectDrawnGameFieldChanges(Validator $validator, ?Game $game): void
    {
        if (! $game) {
            return;
        }

        if ($game->isGeneratedKnockout()) {
            $this->rejectLockedFieldChanges($validator, $game, self::KNOCKOUT_GAME_LOCKED_FIELDS, 'O campo %s não pode ser alterado em partidas geradas pelo mata-mata.');

            return;
        }

        if ($game->group_number === null) {
            if ($this->input('group_number') !== null && ! $validator->errors()->has('group_number')) {
                $validator->errors()->add('group_number', 'O grupo da partida é definido pelo sorteio.');
            }

            return;
        }

        $this->rejectLockedFieldChanges($validator, $game, self::DRAWN_GAME_LOCKED_FIELDS, 'O campo %s não pode ser alterado em partidas sorteadas.');
    }

    private function rejectLockedFieldChanges(Validator $validator, Game $game, array $fields, string $message): void
    {
        foreach ($fields as $field) {
            if (! $this->has($field) || $validator->errors()->has($field)) {
                continue;
            }

            if ((string) $this->input($field) !== (string) $game->getAttribute($field)) {
                $validator->errors()->add($field, sprintf($message, $validator->getDisplayableAttribute($field)));
            }
        }
    }
}

<?php

namespace App\Http\Requests\Concerns;

use App\Models\Game;
use App\Models\League;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesDrawnGames
{
    private const DRAWN_GAME_LOCKED_FIELDS = ['league_id', 'round', 'group_number', 'phase', 'phase_order', 'tie_key', 'leg'];

    protected function rejectManualRoundInDrawLeague(Validator $validator, ?League $league, mixed $round): void
    {
        if ($league && $league->usesDraw() && $round !== null && ! $validator->errors()->has('round')) {
            $validator->errors()->add('round', 'Em ligas com sorteio, as partidas de rodada são criadas pelo sorteio.');
        }
    }

    protected function rejectDrawnGameFieldChanges(Validator $validator, ?Game $game): void
    {
        if (! $game) {
            return;
        }

        if ($game->group_number === null) {
            if ($this->input('group_number') !== null && ! $validator->errors()->has('group_number')) {
                $validator->errors()->add('group_number', 'O grupo da partida é definido pelo sorteio.');
            }

            return;
        }

        foreach (self::DRAWN_GAME_LOCKED_FIELDS as $field) {
            if (! $this->has($field) || $validator->errors()->has($field)) {
                continue;
            }

            if ((string) $this->input($field) !== (string) $game->getAttribute($field)) {
                $validator->errors()->add($field, sprintf(
                    'O campo %s não pode ser alterado em partidas sorteadas.',
                    $validator->getDisplayableAttribute($field),
                ));
            }
        }
    }
}

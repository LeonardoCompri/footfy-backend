<?php

namespace App\Http\Requests\Concerns;

use App\Models\League;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesGameTeams
{
    protected function validateTeamsBelongToLeague(Validator $validator, ?League $league, array $teamIds): void
    {
        $teamIds = array_filter(
            $teamIds,
            fn ($teamId, $field) => $teamId !== null && ! $validator->errors()->has($field),
            ARRAY_FILTER_USE_BOTH
        );

        if (! $league || empty($teamIds)) {
            return;
        }

        $leagueTeamIds = $league->teams()
            ->whereIn('teams.id', array_values($teamIds))
            ->pluck('teams.id')
            ->map(fn ($id) => (int) $id)
            ->all();

        foreach ($teamIds as $field => $teamId) {
            if (! in_array((int) $teamId, $leagueTeamIds, true)) {
                $validator->errors()->add($field, 'O time informado não pertence à liga desta partida.');
            }
        }
    }
}

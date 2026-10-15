<?php

namespace App\Http\Requests\Concerns;

trait LeagueFilterRules
{
    protected function leagueFilterRules(): array
    {
        return [
            'league_id' => ['required', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ];
    }

    protected function optionalLeagueFilterRules(): array
    {
        return [
            'league_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ];
    }
}

<?php

namespace App\Http\Requests\Concerns;

use App\Models\League;

/**
 * Regras das ligas enviadas junto com o campeonato (`leagues[0][name]` etc.), espelhando
 * LeagueStoreRequest. `championship_id` não é aceito aqui: o servidor sempre o define.
 */
trait ChampionshipLeaguesRules
{
    protected function championshipLeaguesRules(): array
    {
        return [
            'leagues' => ['nullable', 'array'],
            'leagues.*' => ['array'],
            'leagues.*.name' => ['required', 'string', 'max:255'],
            'leagues.*.format' => ['nullable', 'string', 'in:'.implode(',', League::FORMATS)],
            'leagues.*.season' => ['nullable', 'string', 'max:255'],
            'leagues.*.organizer' => ['nullable', 'string', 'max:255'],
            'leagues.*.start_date' => ['nullable', 'date'],
            'leagues.*.end_date' => ['nullable', 'date', 'after:leagues.*.start_date'],
        ];
    }
}

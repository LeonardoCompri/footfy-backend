<?php

namespace App\Http\Requests\Concerns;

use App\Models\League;

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

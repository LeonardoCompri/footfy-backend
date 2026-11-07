<?php

namespace App\Http\Requests\Concerns;

use App\Models\League;
use Illuminate\Contracts\Validation\Validator;

trait ChampionshipLeaguesRules
{
    use ValidatesLeagueConfiguration;

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
            ...$this->leagueConfigurationRules('leagues.*.'),
        ];
    }

    protected function adjustChampionshipLeaguesToFormat(): void
    {
        $leagues = $this->input('leagues');

        if (! $this->route('id') || ! is_array($leagues)) {
            return;
        }

        foreach ($leagues as $index => $item) {
            $leagueId = is_array($item) ? ($item['id'] ?? null) : null;

            if (! is_scalar($leagueId) || ! filled($leagueId)) {
                continue;
            }

            $league = League::where('championship_id', $this->route('id'))->find($leagueId);
            $leagues[$index] = $this->adjustConfigurationToFormat($item, $league);
        }

        $this->merge(['leagues' => $leagues]);
    }

    protected function validateChampionshipLeagues(Validator $validator): void
    {
        $leagues = $this->input('leagues');

        if (! is_array($leagues) || $validator->errors()->has('leagues')) {
            return;
        }

        foreach ($leagues as $index => $item) {
            if (! is_array($item) || $validator->errors()->has("leagues.{$index}.id")) {
                continue;
            }

            $league = null;

            if ($this->route('id') && filled($item['id'] ?? null)) {
                $league = League::where('championship_id', $this->route('id'))->find($item['id']);
            }

            $this->validateLeagueConfiguration($validator, $item, $league, "leagues.{$index}.");
        }
    }
}

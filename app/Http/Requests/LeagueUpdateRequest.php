<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesLeagueConfiguration;
use App\Models\League;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class LeagueUpdateRequest extends FormRequest
{
    use ValidatesLeagueConfiguration;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'organizer' => ['nullable', 'string', 'max:255'],
            'season' => ['nullable', 'string', 'max:255'],
            'championship_id' => ['nullable', 'integer', 'exists:championships,id'],
            'format' => ['sometimes', 'string', 'in:'.implode(',', League::FORMATS)],
            ...$this->leagueConfigurationRules(),
        ];
    }

    protected function prepareForValidation(): void
    {
        $league = $this->route('id') ? League::find($this->route('id')) : null;
        $input = $this->all();

        $this->merge(array_diff_key($this->adjustConfigurationToFormat($input, $league), $input));
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $league = $this->route('id') ? League::find($this->route('id')) : null;

            $this->validateLeagueConfiguration($validator, $this->all(), $league);
        });
    }
}

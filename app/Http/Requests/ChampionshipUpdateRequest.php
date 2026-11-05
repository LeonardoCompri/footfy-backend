<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChampionshipLeaguesRules;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class ChampionshipUpdateRequest extends FormRequest
{
    use ChampionshipLeaguesRules;

    public function rules(): array
    {
        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'image' => ['sometimes', 'nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            ...$this->championshipLeaguesRules(),
            // Liga com id é atualizada; só vale liga deste campeonato.
            'leagues.*.id' => [
                'nullable',
                'integer',
                'distinct',
                Rule::exists('leagues', 'id')->where('championship_id', $this->route('id')),
            ],
        ];
    }
}

<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ChampionshipLeaguesRules;
use Illuminate\Foundation\Http\FormRequest;

class ChampionshipStoreRequest extends FormRequest
{
    use ChampionshipLeaguesRules;

    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'image' => ['nullable', 'image', 'mimes:jpeg,png,jpg,webp', 'max:2048'],
            ...$this->championshipLeaguesRules(),
        ];
    }
}

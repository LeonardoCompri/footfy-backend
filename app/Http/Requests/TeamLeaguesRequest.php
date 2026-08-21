<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class TeamLeaguesRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'league_ids' => ['required', 'array'],
            'league_ids.*' => ['integer', 'exists:leagues,id'],
        ];
    }
}

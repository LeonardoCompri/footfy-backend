<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GameStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'championship_id' => ['required', 'integer', 'exists:championships,id'],
            'home_team_id'    => ['required', 'integer', 'exists:teams,id'],
            'away_team_id'    => ['required', 'integer', 'exists:teams,id', 'different:home_team_id'],
            'stadium_id'      => ['nullable', 'integer', 'exists:stadiums,id'],
            'match_date'      => ['nullable', 'date'],
            'home_score'      => ['nullable', 'integer', 'min:0'],
            'away_score'      => ['nullable', 'integer', 'min:0'],
            'round'           => ['nullable', 'integer', 'min:1'],
            'status'          => ['required', 'string', 'in:scheduled,in_progress,finished,cancelled'],
        ];
    }
}

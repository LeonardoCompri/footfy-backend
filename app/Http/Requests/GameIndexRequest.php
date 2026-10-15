<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LeagueFilterRules;
use App\Http\Requests\Concerns\PaginationRules;
use Illuminate\Foundation\Http\FormRequest;

class GameIndexRequest extends FormRequest
{
    use LeagueFilterRules, PaginationRules;

    public function rules(): array
    {
        return array_merge($this->leagueFilterRules(), $this->paginationRules(), [
            'round' => ['nullable', 'integer', 'min:1'],
            'phase' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:scheduled,in_progress,finished,cancelled'],
            'team_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
            'league_id' => ['required', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ]);
    }
}

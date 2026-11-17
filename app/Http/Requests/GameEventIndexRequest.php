<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LeagueFilterRules;
use App\Http\Requests\Concerns\PaginationRules;
use Illuminate\Foundation\Http\FormRequest;

class GameEventIndexRequest extends FormRequest
{
    use LeagueFilterRules, PaginationRules;

    private const ID_RULES = ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'];

    public function rules(): array
    {
        return array_merge($this->optionalLeagueFilterRules(), $this->paginationRules(), [
            'type' => ['nullable', 'string'],
            'game_id' => self::ID_RULES,
            'team_id' => self::ID_RULES,
            'player_id' => self::ID_RULES,
        ]);
    }
}

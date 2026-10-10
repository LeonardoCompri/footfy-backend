<?php

namespace App\Http\Requests;

class GameIndexRequest extends LeagueFilterRequest
{
    public function rules(): array
    {
        return array_merge(parent::rules(), [
            'round' => ['nullable', 'integer', 'min:1'],
            'phase' => ['nullable', 'string', 'max:255'],
            'status' => ['nullable', 'in:scheduled,in_progress,finished,cancelled'],
            'team_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);
    }
}

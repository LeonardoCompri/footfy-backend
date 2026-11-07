<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeagueGroupsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'groups' => ['required', 'list'],
            'groups.*' => ['array'],
            'groups.*.team_id' => ['required', 'integer', 'distinct'],
            'groups.*.group_number' => ['required', 'integer', 'min:1'],
        ];
    }
}

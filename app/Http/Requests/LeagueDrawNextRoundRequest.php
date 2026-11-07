<?php

namespace App\Http\Requests;

class LeagueDrawNextRoundRequest extends LeagueDrawRoundRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'replace_manual_rounds' => ['sometimes', 'boolean'],
        ];
    }
}

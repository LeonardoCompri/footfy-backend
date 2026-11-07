<?php

namespace App\Http\Requests;

class LeagueKnockoutNextStageRequest extends LeagueKnockoutStageRequest
{
    public function rules(): array
    {
        return [
            ...parent::rules(),
            'replace_manual_phases' => ['sometimes', 'boolean'],
        ];
    }
}

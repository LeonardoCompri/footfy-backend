<?php

namespace App\Http\Requests;

use App\Services\Knockout\KnockoutBracket;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class LeagueKnockoutStageRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'stage' => ['required', 'string', Rule::in(KnockoutBracket::stepKeys())],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LeagueDrawRoundRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'round' => ['required', 'integer', 'min:1'],
        ];
    }
}

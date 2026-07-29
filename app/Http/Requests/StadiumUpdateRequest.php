<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StadiumUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'league_id' => ['sometimes', 'integer', 'exists:leagues,id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'city' => ['sometimes', 'string', 'max:255'],
            'capacity' => ['sometimes', 'integer', 'min:0'],
        ];
    }
}

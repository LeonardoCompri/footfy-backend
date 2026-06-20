<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class StadiumStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'league_id' => ['required', 'integer', 'exists:leagues,id'],
            'name' => ['required', 'string', 'max:255'],
            'city' => ['required', 'string', 'max:255'],
            'capacity' => ['required', 'integer', 'min:0'],
        ];
    }
}

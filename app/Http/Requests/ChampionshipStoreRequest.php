<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class ChampionshipStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:255'],
            'start_date' => ['nullable', 'date'],
            'end_date' => ['nullable', 'date', 'after:start_date'],
            'organizer' => ['nullable', 'string', 'max:255']
        ];
    }
}

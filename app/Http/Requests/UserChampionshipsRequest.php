<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserChampionshipsRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'championship_ids'   => ['required', 'array'],
            'championship_ids.*' => ['integer', 'exists:championships,id'],
        ];
    }
}

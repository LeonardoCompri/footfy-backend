<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class GameEventIndexRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'type' => ['nullable', 'string'],
            'game_id' => ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

// Valida os filtros opcionais ?type= e ?game_id= de GET /game-events, mesmo motivo do
// LeagueFilterRequest: array/valor não numérico viraria TypeError/500 ou cast frouxo.
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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

/**
 * Excluir a liga apaga em cascata partidas, eventos, estádios e vínculos: exige a senha do master.
 */
class LeagueDestroyRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'password' => ['required', 'string', 'current_password:sanctum'],
        ];
    }

    public function messages(): array
    {
        return [
            'password.current_password' => 'Senha incorreta.',
        ];
    }
}

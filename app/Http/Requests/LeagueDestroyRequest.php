<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

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

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class LoginRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'document' => ['required', 'string', 'regex:/^\d{3}\.?\d{3}\.?\d{3}\-?\d{2}$/'],
            'password' => ['required', 'string'],
        ];
    }
}

<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    public function rules(): array
    {
        return [
            'name'     => ['required', 'string', 'max:255'],
            'email'    => ['nullable', 'email', 'unique:users,email'],
            'document' => ['required', 'string', 'size:11', 'unique:users,document'],
            'password' => ['required', 'string', 'min:6'],
            'role'     => ['required', 'string', 'in:master,admin,coach,player'],
        ];
    }
}

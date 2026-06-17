<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;

class UserUpdateRequest extends FormRequest
{
    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name'     => ['sometimes', 'string', 'max:255'],
            'email'    => ['sometimes', 'nullable', 'email', "unique:users,email,{$userId}"],
            'document' => ['sometimes', 'string', 'size:11', "unique:users,document,{$userId}"],
            'password' => ['sometimes', 'string', 'min:6'],
            'role'     => ['sometimes', 'string', 'in:master,admin,coach,player'],
        ];
    }
}

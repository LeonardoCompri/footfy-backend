<?php

namespace App\Http\Requests;

use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

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
            // Só master altera papel; para os demais o campo é rejeitado (422).
            'role'     => [
                Rule::prohibitedIf(fn () => ! $this->user()->isMaster()),
                'sometimes', 'string', 'in:master,admin,coach,player',
            ],
        ];
    }
}

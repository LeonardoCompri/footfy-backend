<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserLinks;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;

class UserStoreRequest extends FormRequest
{
    use ValidatesUserLinks;

    public function rules(): array
    {
        $role = is_string($this->input('role')) ? $this->input('role') : null;

        return [
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'unique:users,email'],
            'document' => ['required', 'string', 'size:11', 'unique:users,document'],
            'password' => ['required', 'string', 'min:6'],
            'role' => ['required', 'string', 'in:master,admin,coach,player'],
            ...$this->userLinkRules($role, null, false),
        ];
    }

    public function messages(): array
    {
        return $this->userLinkMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePlayerIsFree($validator, null));
    }
}

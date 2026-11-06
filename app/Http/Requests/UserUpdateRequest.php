<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesUserLinks;
use App\Models\User;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class UserUpdateRequest extends FormRequest
{
    use ValidatesUserLinks;

    public function rules(): array
    {
        $userId = $this->route('id');

        return [
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['sometimes', 'nullable', 'email', "unique:users,email,{$userId}"],
            'document' => ['sometimes', 'string', 'size:11', "unique:users,document,{$userId}"],
            'password' => ['sometimes', 'string', 'min:6'],
            'role' => [
                Rule::prohibitedIf(fn () => ! $this->user()->isMaster()),
                'sometimes', 'string', 'in:master,admin,coach,player',
            ],
            ...$this->userLinkRules($this->effectiveRole(), (int) $userId, true),
        ];
    }

    public function messages(): array
    {
        return $this->userLinkMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validatePlayerIsFree($validator, (int) $this->route('id')));
    }

    private function effectiveRole(): ?string
    {
        if ($this->has('role')) {
            return is_string($this->input('role')) ? $this->input('role') : null;
        }

        return User::find($this->route('id'))?->getRoleNames()->first();
    }
}

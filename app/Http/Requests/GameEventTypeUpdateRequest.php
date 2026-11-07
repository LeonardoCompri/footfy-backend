<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGameEventTypeFlags;
use App\Models\GameEventType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameEventTypeUpdateRequest extends FormRequest
{
    use ValidatesGameEventTypeFlags;

    public function rules(): array
    {
        return [
            'slug' => ['prohibited'],
            'name' => ['sometimes', 'required', 'string', 'max:60'],
            'abbreviation' => ['sometimes', ...$this->abbreviationRules($this->route('id'))],
            'icon' => ['sometimes', 'required', 'string', 'max:60', 'regex:/^mdi-[a-z0-9-]+$/'],
            'color' => ['sometimes', 'required', 'string', Rule::in(GameEventType::COLORS)],
            'counts_for' => ['sometimes', 'nullable', 'string', Rule::in(GameEventType::COUNTS_FOR)],
            'allows_assist' => ['sometimes', 'boolean'],
            'card' => ['sometimes', 'nullable', 'string', Rule::in(GameEventType::CARDS)],
            'sort_order' => ['sometimes', 'required', 'integer', 'min:0', 'max:65535'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return [
            ...$this->flagMessages(),
            'slug.prohibited' => 'O identificador do tipo de evento não pode ser alterado.',
        ];
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(function (Validator $validator) {
            $current = $this->route('id') ? GameEventType::find($this->route('id')) : null;

            $this->validateFlags($validator, $current);
        });
    }
}

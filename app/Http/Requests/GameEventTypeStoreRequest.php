<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\ValidatesGameEventTypeFlags;
use App\Models\GameEventType;
use Illuminate\Contracts\Validation\Validator;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class GameEventTypeStoreRequest extends FormRequest
{
    use ValidatesGameEventTypeFlags;

    public function rules(): array
    {
        return [
            'slug' => ['required', 'string', 'max:50', 'regex:/^[a-z0-9_]+$/', 'unique:game_event_types,slug'],
            'name' => ['required', 'string', 'max:60'],
            'icon' => ['required', 'string', 'max:60', 'regex:/^mdi-[a-z0-9-]+$/'],
            'color' => ['required', 'string', Rule::in(GameEventType::COLORS)],
            'counts_for' => ['nullable', 'string', Rule::in(GameEventType::COUNTS_FOR)],
            'allows_assist' => ['sometimes', 'boolean'],
            'card' => ['nullable', 'string', Rule::in(GameEventType::CARDS)],
            'sort_order' => ['nullable', 'integer', 'min:0', 'max:65535'],
            'active' => ['sometimes', 'boolean'],
        ];
    }

    public function messages(): array
    {
        return $this->flagMessages();
    }

    public function withValidator(Validator $validator): void
    {
        $validator->after(fn (Validator $validator) => $this->validateFlags($validator));
    }
}

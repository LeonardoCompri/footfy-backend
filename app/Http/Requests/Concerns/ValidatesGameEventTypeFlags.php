<?php

namespace App\Http\Requests\Concerns;

use App\Models\GameEventType;
use Illuminate\Contracts\Validation\Validator;

trait ValidatesGameEventTypeFlags
{
    protected function validateFlags(Validator $validator, ?GameEventType $current = null): void
    {
        if ($validator->errors()->hasAny(GameEventType::BEHAVIOR_FIELDS)) {
            return;
        }

        $countsFor = $this->input('counts_for', $current?->counts_for);
        $card = $this->input('card', $current?->card);
        $allowsAssist = $this->has('allows_assist')
            ? filter_var($this->input('allows_assist'), FILTER_VALIDATE_BOOLEAN)
            : (bool) $current?->allows_assist;

        if ($card !== null && $countsFor !== null) {
            $validator->errors()->add('card', 'Um tipo de cartão não pode contar no placar.');
        }

        if ($allowsAssist && $countsFor !== 'team') {
            $validator->errors()->add('allows_assist', 'Só tipos que contam no placar para o próprio time permitem assistência.');
        }
    }

    protected function flagMessages(): array
    {
        return [
            'slug.regex' => 'O identificador deve ter apenas letras minúsculas, números e _.',
            'icon.regex' => 'O ícone deve ser um nome do Material Design Icons (ex.: mdi-soccer).',
            'color.in' => 'A cor informada não está entre as cores do tema.',
        ];
    }
}

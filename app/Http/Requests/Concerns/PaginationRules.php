<?php

namespace App\Http\Requests\Concerns;

trait PaginationRules
{
    protected function paginationRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:100'],
        ];
    }

    protected function paginationMessages(): array
    {
        return [
            'page.integer' => 'A página deve ser um número inteiro.',
            'page.min' => 'A página deve ser no mínimo 1.',
            'per_page.integer' => 'A quantidade de itens por página deve ser um número inteiro.',
            'per_page.min' => 'A quantidade de itens por página deve ser no mínimo 1.',
            'per_page.max' => 'A quantidade de itens por página deve ser no máximo 100.',
        ];
    }

    protected function prepareForValidation(): void
    {
        $this->merge([
            'page' => $this->input('page') ?? 1,
            'per_page' => $this->input('per_page') ?? 20,
        ]);
    }

    protected function paginationAttributes(): array
    {
        return [
            'page' => 'página',
            'per_page' => 'itens por página',
        ];
    }
}

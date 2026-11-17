<?php

namespace App\Http\Requests\Concerns;

trait PaginationRules
{
    public function wantsPagination(): bool
    {
        return $this->filled('page');
    }

    protected function paginationRules(): array
    {
        return [
            'page' => ['nullable', 'integer', 'min:1'],
            'per_page' => ['nullable', 'integer', 'min:1', 'max:'.config('footfy.pagination.max_per_page')],
        ];
    }
}

<?php

namespace App\Http\Requests\Concerns;

use Illuminate\Validation\Rule;

trait ListingRules
{
    use PaginationRules;

    protected function listingRules(array $sortableColumns): array
    {
        return array_merge($this->paginationRules(), [
            'search' => ['nullable', 'string', 'max:100'],
            'sort' => ['nullable', 'string', Rule::in($sortableColumns)],
            'order' => ['nullable', 'string', 'in:asc,desc'],
        ]);
    }
}

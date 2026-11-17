<?php

namespace App\Repositories\Concerns;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Pagination\LengthAwarePaginator;

trait BuildsListings
{
    protected function searchByName(Builder $query, array $filters): Builder
    {
        return $query->when(filled($filters['search'] ?? null), function (Builder $q) use ($filters) {
            $q->where($q->qualifyColumn('name'), 'like', '%'.$filters['search'].'%');
        });
    }

    protected function sortListing(Builder $query, array $filters, bool $paginate): Builder
    {
        $sort = $filters['sort'] ?? null;
        $order = $filters['order'] ?? null;

        if (! $paginate && $sort === null && $order === null) {
            return $query;
        }

        $sort ??= 'id';
        $query->orderBy($query->qualifyColumn($sort), $order ?? 'asc');

        if ($sort !== 'id') {
            $query->orderBy($query->qualifyColumn('id'));
        }

        return $query;
    }

    protected function getOrPaginate(Builder $query, array $filters, bool $paginate): Collection|LengthAwarePaginator
    {
        if (! $paginate) {
            return $query->get();
        }

        return $query->paginate(
            perPage: (int) ($filters['per_page'] ?? config('footfy.pagination.per_page')),
            page: (int) ($filters['page'] ?? 1),
        );
    }
}

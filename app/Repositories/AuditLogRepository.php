<?php

namespace App\Repositories;

use App\Models\AuditLog;
use App\Repositories\Concerns\BuildsListings;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\LazyCollection;

class AuditLogRepository
{
    use BuildsListings;

    private const PRUNE_CHUNK_SIZE = 1000;

    private const EXACT_FILTERS = ['user_id', 'action', 'league_id', 'championship_id', 'auditable_type', 'auditable_id'];

    private const WITH = ['user:id,name', 'league:id,name', 'championship:id,name'];

    private const EXPORT_CHUNK_SIZE = 500;

    public function index(array $filters): LengthAwarePaginator
    {
        return $this->getOrPaginate($this->filteredQuery($filters), $filters, paginate: true);
    }

    public function count(array $filters): int
    {
        return $this->filteredQuery($filters)->toBase()->getCountForPagination();
    }

    public function export(array $filters): LazyCollection
    {
        return $this->filteredQuery($filters)->reorder()->lazyByIdDesc(self::EXPORT_CHUNK_SIZE);
    }

    public function maxId(): int
    {
        return (int) AuditLog::query()->max('id');
    }

    public function store(array $data): AuditLog
    {
        return AuditLog::query()->create($data);
    }

    private function filteredQuery(array $filters): Builder
    {
        $query = AuditLog::query()->with(self::WITH);

        foreach (self::EXACT_FILTERS as $column) {
            $query->when(filled($filters[$column] ?? null), fn (Builder $q) => $q->where($column, $filters[$column]));
        }

        return $query
            ->when(isset($filters['created_from']), fn (Builder $q) => $q->where('created_at', '>=', $filters['created_from']))
            ->when(isset($filters['created_before']), fn (Builder $q) => $q->where('created_at', '<', $filters['created_before']))
            ->when(isset($filters['max_id']), fn (Builder $q) => $q->where('id', '<=', $filters['max_id']))
            ->orderByDesc('created_at')
            ->orderByDesc('id');
    }

    public function deleteCreatedBefore(CarbonInterface $date): int
    {
        $total = 0;

        do {
            $deleted = AuditLog::query()
                ->where('created_at', '<', $date)
                ->limit(self::PRUNE_CHUNK_SIZE)
                ->delete();

            $total += $deleted;
        } while ($deleted === self::PRUNE_CHUNK_SIZE);

        return $total;
    }
}

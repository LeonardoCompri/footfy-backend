<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;

class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $repository) {}

    public function index(array $filters): LengthAwarePaginator
    {
        if (filled($filters['from'] ?? null)) {
            $filters['created_from'] = CarbonImmutable::createFromFormat('Y-m-d', $filters['from'])->startOfDay();
        }

        if (filled($filters['to'] ?? null)) {
            $filters['created_before'] = CarbonImmutable::createFromFormat('Y-m-d', $filters['to'])->addDay()->startOfDay();
        }

        if (filled($filters['auditable_type'] ?? null)) {
            $filters['auditable_type'] = AuditLog::auditableClass($filters['auditable_type']);
        }

        return $this->repository->index($filters);
    }

    public function prune(int $retentionMonths): int
    {
        return $this->repository->deleteCreatedBefore(now()->subMonthsNoOverflow($retentionMonths));
    }
}

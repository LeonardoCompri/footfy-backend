<?php

namespace App\Services;

use App\Repositories\AuditLogRepository;

class AuditLogService
{
    public function __construct(private readonly AuditLogRepository $repository) {}

    public function prune(int $retentionMonths): int
    {
        return $this->repository->deleteCreatedBefore(now()->subMonthsNoOverflow($retentionMonths));
    }
}

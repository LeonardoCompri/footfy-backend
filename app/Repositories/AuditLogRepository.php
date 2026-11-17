<?php

namespace App\Repositories;

use App\Models\AuditLog;
use Carbon\CarbonInterface;

class AuditLogRepository
{
    private const PRUNE_CHUNK_SIZE = 1000;

    public function store(array $data): AuditLog
    {
        return AuditLog::query()->create($data);
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

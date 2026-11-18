<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Repositories\AuditLogRepository;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\LazyCollection;
use Illuminate\Validation\ValidationException;

class AuditLogService
{
    public function __construct(
        private readonly AuditLogRepository $repository,
        private readonly AuditLogger $auditLogger,
    ) {}

    public function index(array $filters): LengthAwarePaginator
    {
        return $this->repository->index($this->normalizeFilters($filters));
    }

    public function export(array $filters): LazyCollection
    {
        $query = [...$this->normalizeFilters($filters), 'max_id' => $this->repository->maxId()];
        $rows = $this->repository->count($query);
        $limit = (int) config('audit.export_max_rows');

        if ($rows > $limit) {
            throw ValidationException::withMessages([
                'message' => sprintf(
                    'A busca tem %s registros; o limite da exportação é %s. Refine os filtros.',
                    $this->formatNumber($rows),
                    $this->formatNumber($limit),
                ),
            ]);
        }

        $this->auditLogger->record('audit_logs.exported', Auth::user(), meta: [
            'filters' => array_filter($filters, fn ($value) => filled($value)),
            'rows' => $rows,
        ]);

        return $this->repository->export($query);
    }

    public function prune(int $retentionMonths): int
    {
        return $this->repository->deleteCreatedBefore(now()->subMonthsNoOverflow($retentionMonths));
    }

    private function normalizeFilters(array $filters): array
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

        return $filters;
    }

    private function formatNumber(int $value): string
    {
        return number_format($value, 0, ',', '.');
    }
}

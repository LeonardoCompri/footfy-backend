<?php

namespace App\Console\Commands;

use App\Services\AuditLogService;
use Illuminate\Console\Command;

class PruneAuditLogs extends Command
{
    protected $signature = 'audit:prune';

    protected $description = 'Remove os registros de auditoria mais antigos que a retenção configurada';

    public function handle(AuditLogService $service): int
    {
        $retentionMonths = (int) config('audit.retention_months');

        if ($retentionMonths < 1) {
            $this->error('A retenção da auditoria precisa ser de pelo menos 1 mês.');

            return self::FAILURE;
        }

        $deleted = $service->prune($retentionMonths);

        $this->info("{$deleted} registro(s) de auditoria removido(s).");

        return self::SUCCESS;
    }
}

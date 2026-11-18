<?php

namespace App\Http\Resources;

use App\Models\AuditLog;
use Illuminate\Database\Eloquent\Model;

class AuditLogCsvRow
{
    public const HEADERS = [
        'Data (horário de Brasília)',
        'Usuário',
        'Ação',
        'Entidade',
        'Campeonato',
        'Liga',
        'Antes',
        'Depois',
        'Meta',
        'IP',
        'ID da requisição',
    ];

    private const TIMEZONE = 'America/Sao_Paulo';

    private const DATE_FORMAT = 'd/m/Y H:i:s';

    private const FORMULA_PREFIXES = ['=', '+', '-', '@', "\t", "\r"];

    private readonly array $actionLabels;

    public function __construct()
    {
        $labels = trans('audit.actions');
        $this->actionLabels = is_array($labels) ? $labels : [];
    }

    public function toArray(AuditLog $log): array
    {
        return array_map($this->escape(...), [
            $log->created_at->copy()->setTimezone(self::TIMEZONE)->format(self::DATE_FORMAT),
            $this->userLabel($log),
            $this->actionLabels[$log->action] ?? $log->action,
            $log->auditableAlias().' #'.$log->auditable_id,
            $this->reference($log->championship_id, $log->championship, 'Campeonato #%d (excluído)'),
            $this->reference($log->league_id, $log->league, 'Liga #%d (excluída)'),
            $this->json($log->old_values),
            $this->json($log->new_values),
            $this->json($log->meta),
            (string) $log->ip,
            (string) $log->request_id,
        ]);
    }

    private function userLabel(AuditLog $log): string
    {
        if ($log->user_id === null) {
            return 'Sistema';
        }

        return $this->reference($log->user_id, $log->user, 'Usuário #%d (excluído)');
    }

    private function reference(?int $id, ?Model $model, string $deletedLabel): string
    {
        if ($id === null) {
            return '';
        }

        return $model?->name ?? sprintf($deletedLabel, $id);
    }

    private function json(?array $values): string
    {
        if ($values === null) {
            return '';
        }

        return json_encode($values, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR);
    }

    private function escape(string $value): string
    {
        if ($value !== '' && in_array($value[0], self::FORMULA_PREFIXES, true)) {
            return "'".$value;
        }

        return $value;
    }
}

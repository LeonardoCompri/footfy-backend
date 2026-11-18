<?php

namespace App\Http\Requests\Concerns;

use App\Models\AuditLog;
use Illuminate\Validation\Rule;

trait AuditLogFilterRules
{
    use LeagueFilterRules;

    private const AUDIT_LOG_ID_RULES = ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'];

    protected function auditLogFilterRules(): array
    {
        return array_merge($this->optionalLeagueFilterRules(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => self::AUDIT_LOG_ID_RULES,
            'action' => ['nullable', 'string', 'max:100'],
            'championship_id' => self::AUDIT_LOG_ID_RULES,
            'auditable_type' => ['nullable', 'required_with:auditable_id', 'string', Rule::in(array_keys(AuditLog::AUDITABLE_TYPES))],
            'auditable_id' => self::AUDIT_LOG_ID_RULES,
        ]);
    }
}

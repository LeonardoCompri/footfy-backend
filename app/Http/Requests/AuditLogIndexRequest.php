<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\LeagueFilterRules;
use App\Http\Requests\Concerns\PaginationRules;
use App\Models\AuditLog;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Validation\Rule;

class AuditLogIndexRequest extends FormRequest
{
    use LeagueFilterRules, PaginationRules;

    private const ID_RULES = ['nullable', 'integer', 'min:1', 'regex:/^[0-9]+$/'];

    public function rules(): array
    {
        return array_merge($this->optionalLeagueFilterRules(), $this->paginationRules(), [
            'from' => ['nullable', 'date_format:Y-m-d'],
            'to' => ['nullable', 'date_format:Y-m-d', 'after_or_equal:from'],
            'user_id' => self::ID_RULES,
            'action' => ['nullable', 'string', 'max:100'],
            'championship_id' => self::ID_RULES,
            'auditable_type' => ['nullable', 'required_with:auditable_id', 'string', Rule::in(array_keys(AuditLog::AUDITABLE_TYPES))],
            'auditable_id' => self::ID_RULES,
        ]);
    }
}

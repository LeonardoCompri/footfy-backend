<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuditLogFilterRules;
use App\Http\Requests\Concerns\PaginationRules;
use Illuminate\Foundation\Http\FormRequest;

class AuditLogIndexRequest extends FormRequest
{
    use AuditLogFilterRules, PaginationRules;

    public function rules(): array
    {
        return array_merge($this->auditLogFilterRules(), $this->paginationRules());
    }
}

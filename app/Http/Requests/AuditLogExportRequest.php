<?php

namespace App\Http\Requests;

use App\Http\Requests\Concerns\AuditLogFilterRules;
use Illuminate\Foundation\Http\FormRequest;

class AuditLogExportRequest extends FormRequest
{
    use AuditLogFilterRules;

    public function rules(): array
    {
        return $this->auditLogFilterRules();
    }
}

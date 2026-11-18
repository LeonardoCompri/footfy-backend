<?php

namespace App\Models\Concerns;

use App\Models\AuditLog;
use App\Models\League;
use App\Services\AuditLogger;

trait Auditable
{
    public function auditChanges(): array
    {
        $attributes = array_diff(array_keys($this->getDirty()), $this->auditExcludedAttributes());
        $old = [];
        $new = [];

        foreach ($attributes as $attribute) {
            if ($this->exists) {
                $old[$attribute] = $this->getOriginal($attribute);
            }

            $new[$attribute] = $this->getAttributeValue($attribute);
        }

        return ['old' => $old, 'new' => $new];
    }

    public function recordAudit(string $action, ?array $changes = null, array $meta = [], ?League $league = null): AuditLog
    {
        $changes ??= $this->auditChanges();

        return app(AuditLogger::class)->record($action, $this, $changes['old'] ?? [], $changes['new'] ?? [], $meta, $league);
    }

    protected function auditExcludedAttributes(): array
    {
        return array_filter([$this->getCreatedAtColumn(), $this->getUpdatedAtColumn()]);
    }
}

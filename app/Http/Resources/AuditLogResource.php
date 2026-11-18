<?php

namespace App\Http\Resources;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AuditLogResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'action' => $this->action,
            'user' => $this->reference($this->user_id, $this->user),
            'auditable' => [
                'type' => $this->auditableAlias(),
                'id' => $this->auditable_id,
            ],
            'championship' => $this->reference($this->championship_id, $this->championship),
            'league' => $this->reference($this->league_id, $this->league),
            'old_values' => $this->old_values,
            'new_values' => $this->new_values,
            'meta' => $this->meta,
            'ip' => $this->ip,
            'user_agent' => $this->user_agent,
            'request_id' => $this->request_id,
            'created_at' => $this->created_at,
        ];
    }

    private function reference(?int $id, ?Model $model): ?array
    {
        if ($id === null) {
            return null;
        }

        return [
            'id' => $id,
            'name' => $model?->name,
        ];
    }
}

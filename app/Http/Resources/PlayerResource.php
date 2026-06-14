<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class PlayerResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'team_id' => $this->team_id,
            'name' => $this->name,
            'position' => $this->position,
            'number' => $this->number,
            'birth_date' => $this->birth_date,
            'document' => $this->document,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameEventTypeResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'slug' => $this->slug,
            'name' => $this->name,
            'abbreviation' => $this->abbreviation,
            'icon' => $this->icon,
            'color' => $this->color,
            'counts_for' => $this->counts_for,
            'allows_assist' => $this->allows_assist,
            'card' => $this->card,
            'sort_order' => $this->sort_order,
            'active' => $this->active,
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GoalResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'         => $this->id,
            'game_id'    => $this->game_id,
            'player_id'  => $this->player_id,
            'team_id'    => $this->team_id,
            'minute'     => $this->minute,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

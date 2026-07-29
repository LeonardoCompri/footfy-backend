<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameEventResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'game_id' => $this->game_id,
            'team_id' => $this->team_id,
            'player_id' => $this->player_id,
            'related_player_id' => $this->related_player_id,
            'league_id' => $this->league_id,
            'type' => $this->type,
            'minute' => $this->minute,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

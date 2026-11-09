<?php

namespace App\Http\Resources;

use App\Models\Player;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GamePenaltyKickResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'order' => $this->order,
            'team_id' => $this->team_id,
            'player_id' => $this->player_id,
            'scored' => $this->scored,
            'player' => $this->whenLoaded('player', fn (Player $player) => [
                'id' => $player->id,
                'name' => $player->name,
                'number' => $player->number,
                'removed' => $player->trashed(),
            ]),
        ];
    }
}

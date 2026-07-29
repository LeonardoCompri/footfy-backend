<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class LeagueResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'start_date' => $this->start_date,
            'end_date' => $this->end_date,
            'organizer' => $this->organizer,
            'season' => $this->season,
            'championship_id' => $this->championship_id,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'games' => GameResource::collection($this->whenLoaded('games')),
        ];
    }
}

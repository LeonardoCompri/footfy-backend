<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'league_id' => $this->league_id,
            'home_team_id' => $this->home_team_id,
            'away_team_id' => $this->away_team_id,
            'stadium_id' => $this->stadium_id,
            'match_date' => $this->match_date,
            'home_score' => $this->home_score,
            'away_score' => $this->away_score,
            'round' => $this->round,
            'group_number' => $this->group_number,
            'phase' => $this->phase,
            'phase_order' => $this->phase_order,
            'tie_key' => $this->tie_key,
            'leg' => $this->leg,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'homeTeam' => new TeamResource($this->whenLoaded('homeTeam')),
            'awayTeam' => new TeamResource($this->whenLoaded('awayTeam')),
            'stadium' => new StadiumResource($this->whenLoaded('stadium')),
            'events' => GameEventResource::collection($this->whenLoaded('events')),
        ];
    }
}

<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class GameResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'championship_id' => $this->championship_id,
            'home_team_id'    => $this->home_team_id,
            'away_team_id'    => $this->away_team_id,
            'stadium_id'      => $this->stadium_id,
            'match_date'      => $this->match_date,
            'home_score'      => $this->home_score,
            'away_score'      => $this->away_score,
            'round'           => $this->round,
            'status'          => $this->status,
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}

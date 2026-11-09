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
            'home_penalties' => $this->home_penalties,
            'away_penalties' => $this->away_penalties,
            'penalties_allowed' => $this->when($this->resource->penaltiesAllowed !== null, fn () => $this->resource->penaltiesAllowed),
            'penalties_possible' => $this->when($this->resource->penaltiesPossible !== null, fn () => $this->resource->penaltiesPossible),
            'results_locked' => $this->when($this->resource->resultsLocked !== null, fn () => $this->resource->resultsLocked),
            'results_lock' => $this->when($this->resource->resultsLocked !== null, fn () => $this->resource->resultsLock),
            'round' => $this->round,
            'group_number' => $this->group_number,
            'phase' => $this->phase,
            'phase_order' => $this->phase_order,
            'tie_key' => $this->tie_key,
            'leg' => $this->leg,
            'knockout_stage' => $this->knockout_stage,
            'status' => $this->status,
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'league' => $this->whenLoaded('league', fn () => [
                'id' => $this->league->id,
                'name' => $this->league->name,
                'championship' => $this->league->championship ? [
                    'id' => $this->league->championship->id,
                    'name' => $this->league->championship->name,
                ] : null,
            ]),
            'homeTeam' => new TeamResource($this->whenLoaded('homeTeam')),
            'awayTeam' => new TeamResource($this->whenLoaded('awayTeam')),
            'stadium' => new StadiumResource($this->whenLoaded('stadium')),
            'events' => GameEventResource::collection($this->whenLoaded('events')),
            'penaltyKicks' => GamePenaltyKickResource::collection($this->whenLoaded('penaltyKicks')),
        ];
    }
}

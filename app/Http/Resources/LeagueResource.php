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
            'format' => $this->format,
            'fixture_mode' => $this->fixture_mode,
            'groups_count' => $this->groups_count,
            'group_legs' => $this->group_legs,
            'qualifiers_per_group' => $this->qualifiers_per_group,
            'knockout_size' => $this->knockout_size,
            'knockout_legs' => $this->knockout_legs,
            'final_legs' => $this->final_legs,
            'third_place' => $this->third_place,
            'tiebreakers' => $this->tiebreakers,
            'knockout_tiebreakers' => $this->knockout_tiebreakers,
            'max_players_per_team' => $this->max_players_per_team,
            'championship' => new ChampionshipResource($this->whenLoaded('championship')),
            'teams' => TeamResource::collection($this->whenLoaded('teams')),
            'games' => GameResource::collection($this->whenLoaded('games')),
            'stadiums' => StadiumResource::collection($this->whenLoaded('stadiums')),
        ];
    }
}

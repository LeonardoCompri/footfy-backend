<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'nickname' => $this->nickname,
            'logo' => $this->logoUrl(),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'players' => PlayerResource::collection($this->whenLoaded('players')),
            'technicalCommitteeMembers' => TechnicalCommitteeMemberResource::collection($this->whenLoaded('technicalCommitteeMembers')),
            'leagues' => $this->whenLoaded('leagues', fn () => $this->leagues->map(fn ($league) => [
                'id' => $league->id,
                'name' => $league->name,
            ])),
        ];
    }
}

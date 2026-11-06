<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'email' => $this->email,
            'document' => $this->document,
            'roles' => $this->getRoleNames(),
            'leagues' => $this->whenLoaded('leagues', fn () => $this->leagues->map(fn ($l) => [
                'id' => $l->id,
                'name' => $l->name,
            ])),
            'team' => $this->when(
                $this->relationLoaded('team') && $this->relationLoaded('player'),
                fn () => ($team = $this->linkedTeam()) ? [
                    'id' => $team->id,
                    'name' => $team->name,
                    'logo' => $team->logoUrl(),
                ] : null,
            ),
            'player' => $this->whenLoaded('player', fn ($player) => [
                'id' => $player->id,
                'name' => $player->name,
                'team_id' => $player->team_id,
            ]),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

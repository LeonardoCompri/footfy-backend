<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChampionshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->image ? Storage::disk('public')->url($this->image) : null,
            // Só no show/store/update (admin e público), que carregam as ligas; vazio se não houver.
            'leagues' => LeagueResource::collection($this->whenLoaded('leagues')),
        ];
    }
}

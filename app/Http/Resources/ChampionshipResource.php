<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Facades\Storage;

class ChampionshipResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        $response = [
            'id' => $this->id,
            'name' => $this->name,
            'image' => $this->image ? Storage::disk('public')->url($this->image) : null,
        ];

        if ($request->route()->getName() == 'public.championships.show') {
            $response['leagues'] = LeagueResource::collection($this->whenLoaded('leagues'));
        }

        return $response;
    }
}

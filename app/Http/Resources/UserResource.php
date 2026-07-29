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
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}

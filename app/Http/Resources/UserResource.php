<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id'              => $this->id,
            'name'            => $this->name,
            'email'           => $this->email,
            'document'        => $this->document,
            'roles'           => $this->getRoleNames(),
            'championships'   => $this->whenLoaded('championships', fn () => $this->championships->map(fn ($c) => [
                'id'   => $c->id,
                'name' => $c->name,
            ])),
            'created_at'      => $this->created_at,
            'updated_at'      => $this->updated_at,
        ];
    }
}

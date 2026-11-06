<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class LeagueDrawPlan extends Model
{
    protected $fillable = [
        'league_id',
        'group_number',
        'turn',
        'payload',
    ];

    protected function casts(): array
    {
        return [
            'group_number' => 'integer',
            'turn' => 'integer',
            'payload' => 'array',
        ];
    }

    public function rounds(): array
    {
        return $this->payload['rounds'] ?? [];
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GamePenaltyKick extends Model
{
    protected $fillable = [
        'game_id',
        'team_id',
        'player_id',
        'order',
        'scored',
    ];

    protected function casts(): array
    {
        return [
            'order' => 'integer',
            'scored' => 'boolean',
        ];
    }

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class)->withTrashed();
    }

    public function scopeInShootoutOrder(Builder $query): Builder
    {
        return $query->orderBy('order');
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class GameEvent extends Model
{
    use HasFactory;

    protected $fillable = [
        'game_id',
        'team_id',
        'player_id',
        'related_player_id',
        'league_id',
        'type',
        'minute',
    ];

    public function game(): BelongsTo
    {
        return $this->belongsTo(Game::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // withTrashed: a súmula sempre mostra o jogador, mesmo depois de removido (soft delete).
    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class)->withTrashed();
    }

    // withTrashed: idem player(), para a assistência.
    public function relatedPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'related_player_id')->withTrashed();
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
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

    public function player(): BelongsTo
    {
        return $this->belongsTo(Player::class)->withTrashed();
    }

    public function relatedPlayer(): BelongsTo
    {
        return $this->belongsTo(Player::class, 'related_player_id')->withTrashed();
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }

    public function eventType(): BelongsTo
    {
        return $this->belongsTo(GameEventType::class, 'type', 'slug');
    }

    public function scopeJoinEventType(Builder $query): Builder
    {
        return $query->join('game_event_types', 'game_event_types.slug', '=', 'game_events.type');
    }

    public function scopeLatestMinuteFirst(Builder $query): Builder
    {
        return $query->orderByDesc($query->qualifyColumn('minute'))
            ->orderByDesc($query->qualifyColumn('id'));
    }
}

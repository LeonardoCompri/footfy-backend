<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    use HasFactory;

    public const UPDATED_AT = null;

    public const AUDITABLE_TYPES = [
        'championship' => Championship::class,
        'league' => League::class,
        'team' => Team::class,
        'game' => Game::class,
        'game_event' => GameEvent::class,
        'game_event_type' => GameEventType::class,
        'player' => Player::class,
        'technical_committee_member' => TechnicalCommitteeMember::class,
        'stadium' => Stadium::class,
        'user' => User::class,
    ];

    protected $fillable = [
        'user_id',
        'action',
        'auditable_type',
        'auditable_id',
        'championship_id',
        'league_id',
        'old_values',
        'new_values',
        'meta',
        'ip',
        'user_agent',
        'request_id',
    ];

    protected function casts(): array
    {
        return [
            'old_values' => 'array',
            'new_values' => 'array',
            'meta' => 'array',
            'created_at' => 'datetime',
        ];
    }

    public static function auditableClass(string $alias): string
    {
        return self::AUDITABLE_TYPES[$alias];
    }

    public function auditableAlias(): string
    {
        return array_search($this->auditable_type, self::AUDITABLE_TYPES, true) ?: $this->auditable_type;
    }

    public function auditable(): MorphTo
    {
        return $this->morphTo();
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}

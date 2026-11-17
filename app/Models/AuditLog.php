<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class AuditLog extends Model
{
    public const UPDATED_AT = null;

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

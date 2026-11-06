<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class GameEventType extends Model
{
    use HasFactory;

    public const COLORS = ['primary', 'secondary', 'success', 'info', 'warning', 'error', 'card-yellow', 'card-red', 'grey'];

    public const COUNTS_FOR = ['team', 'opponent'];

    public const CARDS = ['yellow', 'red'];

    public const BEHAVIOR_FIELDS = ['counts_for', 'allows_assist', 'card'];

    protected $attributes = [
        'allows_assist' => false,
        'active' => true,
    ];

    protected $fillable = [
        'slug',
        'name',
        'icon',
        'color',
        'counts_for',
        'allows_assist',
        'card',
        'sort_order',
        'active',
    ];

    protected function casts(): array
    {
        return [
            'allows_assist' => 'boolean',
            'active' => 'boolean',
            'sort_order' => 'integer',
        ];
    }

    public function events(): HasMany
    {
        return $this->hasMany(GameEvent::class, 'type', 'slug');
    }
}

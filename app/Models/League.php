<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;

class League extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'organizer',
        'season',
        'championship_id',
        'format',
    ];

    public const FORMATS = ['round_robin', 'knockout', 'hybrid'];

    protected $attributes = [
        'format' => 'round_robin',
    ];

    public function championship(): BelongsTo
    {
        return $this->belongsTo(Championship::class);
    }

    public function teams(): BelongsToMany
    {
        return $this->belongsToMany(Team::class, 'league_team');
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'user_leagues');
    }

    public function stadiums(): HasMany
    {
        return $this->hasMany(Stadium::class);
    }
}

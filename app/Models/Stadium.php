<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class Stadium extends Model
{
    use HasFactory;

    protected $fillable = [
        'league_id',
        'name',
        'city',
        'capacity',
    ];

    public function league(): BelongsTo
    {
        return $this->belongsTo(League::class);
    }
}

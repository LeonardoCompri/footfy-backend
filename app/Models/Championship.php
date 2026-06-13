<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Championship extends Model
{
    use HasFactory;

    protected $fillable = [
        'name',
        'start_date',
        'end_date',
        'organizer'
    ];

    public function teams()
    {
        return $this->belongsToMany(Team::class);
    }

    public function games()
    {
        return $this->hasMany(Game::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    protected $fillable = [
        'id',
        'name',
        'nickname',
        'logo',
        'created_at',
        'updated_at'
    ];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function coach()
    {
        return $this->hasOne(Coach::class);
    }

    public function championships()
    {
        return $this->belongsToMany(Championship::class);
    }

    public function homeGames()
    {
        return $this->hasMany(Game::class, 'home_team_id');
    }

    public function awayGames()
    {
        return $this->hasMany(Game::class, 'away_team_id');
    }
}

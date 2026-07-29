<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Team extends Model
{
    use HasFactory;

    protected $fillable = [
        'id',
        'name',
        'nickname',
        'logo',
        'created_at',
        'updated_at',
    ];

    public function players()
    {
        return $this->hasMany(Player::class);
    }

    public function technicalCommitteeMembers()
    {
        return $this->hasMany(TechnicalCommitteeMember::class);
    }

    public function leagues()
    {
        return $this->belongsToMany(League::class, 'league_team');
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

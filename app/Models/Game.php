<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Game extends Model
{
    use HasFactory;

    protected $fillable = [
        'championship_id',
        'home_team_id',
        'away_team_id',
        'stadium_id',
        'match_date',
        'home_score',
        'away_score',
        'round',
        'status',
    ];

    public function championship()
    {
        return $this->belongsTo(Championship::class);
    }

    public function homeTeam()
    {
        return $this->belongsTo(Team::class, 'home_team_id');
    }

    public function awayTeam()
    {
        return $this->belongsTo(Team::class, 'away_team_id');
    }

    public function goals()
    {
        return $this->hasMany(Goal::class);
    }
}

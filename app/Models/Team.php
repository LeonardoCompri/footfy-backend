<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Query\Builder as QueryBuilder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

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

    public static function idsInLeagues(array $leagueIds): QueryBuilder
    {
        return DB::table('league_team')->select('team_id')->whereIn('league_id', $leagueIds);
    }

    public function scopeInLeagues(Builder $query, array $leagueIds): void
    {
        $query->whereIn('teams.id', self::idsInLeagues($leagueIds));
    }

    public function logoUrl(): ?string
    {
        return $this->logo ? Storage::disk('public')->url($this->logo) : null;
    }

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

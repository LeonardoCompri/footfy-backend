<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Illuminate\Support\Facades\DB;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasApiTokens, HasFactory, HasRoles, Notifiable;

    public const TEAM_SCOPED_ROLES = ['coach', 'player'];

    public const LEAGUE_SCOPED_ROLES = ['master', 'admin'];

    protected $fillable = [
        'name',
        'email',
        'document',
        'password',
        'team_id',
    ];

    protected $hidden = [
        'password',
        'remember_token',
    ];

    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
        ];
    }

    public function leagues(): BelongsToMany
    {
        return $this->belongsToMany(League::class, 'user_leagues');
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function player(): HasOne
    {
        return $this->hasOne(Player::class);
    }

    public function isMaster(): bool
    {
        return $this->hasRole('master');
    }

    public function isCoach(): bool
    {
        return $this->hasRole('coach') && ! $this->hasAnyRole(self::LEAGUE_SCOPED_ROLES);
    }

    public function hasTeamScope(): bool
    {
        return $this->hasAnyRole(self::TEAM_SCOPED_ROLES) && ! $this->hasAnyRole(self::LEAGUE_SCOPED_ROLES);
    }

    public function linkedTeam(): ?Team
    {
        return $this->team ?? $this->player?->team;
    }

    public function leagueIds(): array
    {
        return $this->scopedLeagues()->pluck('leagues.id')->toArray();
    }

    public function hasLeagueId(int $leagueId): bool
    {
        return $this->scopedLeagues()->where('leagues.id', $leagueId)->exists();
    }

    private function scopedLeagues(): Builder|BelongsToMany
    {
        if (! $this->hasTeamScope()) {
            return $this->leagues();
        }

        return League::query()->whereIn('leagues.id', DB::table('league_team')
            ->select('league_id')
            ->where(fn ($q) => $q
                ->where('team_id', $this->team_id)
                ->orWhereIn('team_id', Player::where('user_id', $this->id)->select('team_id'))));
    }

    public function scopeInLeagues(Builder $query, array $leagueIds): void
    {
        $teamScoped = fn ($q) => $q
            ->whereHas('roles', fn ($r) => $r->whereIn('name', self::TEAM_SCOPED_ROLES))
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', self::LEAGUE_SCOPED_ROLES));
        $teamIds = Team::idsInLeagues($leagueIds);

        $query->where(fn ($q) => $q
            ->where(fn ($direct) => $direct
                ->whereNot($teamScoped)
                ->whereIn('users.id', DB::table('user_leagues')->select('user_id')->whereIn('league_id', $leagueIds)))
            ->orWhere(fn ($derived) => $derived
                ->where($teamScoped)
                ->where(fn ($team) => $team
                    ->whereIn('users.team_id', $teamIds)
                    ->orWhereIn('users.id', Player::whereIn('team_id', $teamIds)->select('user_id'))))
        );
    }
}

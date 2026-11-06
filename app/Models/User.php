<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
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

        return League::query()->whereHas('teams', fn ($teams) => $teams->where(fn ($q) => $q
            ->where('teams.id', $this->team_id)
            ->orWhereHas('players', fn ($players) => $players->where('players.user_id', $this->id))
        ));
    }

    public function scopeInLeagues(Builder $query, array $leagueIds): void
    {
        $teamScoped = fn ($q) => $q
            ->whereHas('roles', fn ($r) => $r->whereIn('name', self::TEAM_SCOPED_ROLES))
            ->whereDoesntHave('roles', fn ($r) => $r->whereIn('name', self::LEAGUE_SCOPED_ROLES));
        $inLeagues = fn ($l) => $l->whereIn('leagues.id', $leagueIds);

        $query->where(fn ($q) => $q
            ->where(fn ($direct) => $direct
                ->whereNot($teamScoped)
                ->whereHas('leagues', $inLeagues))
            ->orWhere(fn ($derived) => $derived
                ->where($teamScoped)
                ->where(fn ($team) => $team
                    ->whereHas('team.leagues', $inLeagues)
                    ->orWhereHas('player.team.leagues', $inLeagues)))
        );
    }
}

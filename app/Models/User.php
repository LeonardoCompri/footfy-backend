<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
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

    /**
     * Papéis cujas ligas derivam do time (coach: users.team_id; player: time do jogador
     * vinculado em players.user_id), e não de user_leagues.
     */
    public const TEAM_SCOPED_ROLES = ['coach', 'player'];

    /**
     * Papéis com ligas vinculadas manualmente (user_leagues). Têm precedência: um usuário
     * com admin e coach ao mesmo tempo usa user_leagues.
     */
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

    // Time treinado pelo coach (1:1, unique em users.team_id).
    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    // Cadastro de jogador do usuário player (1:1, unique em players.user_id). Jogador
    // removido (soft delete) não conta: o player fica sem time e sem ligas.
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

    // Coach/player sem papel master/admin: ligas derivadas do time.
    public function hasTeamScope(): bool
    {
        return $this->hasAnyRole(self::TEAM_SCOPED_ROLES) && ! $this->hasAnyRole(self::LEAGUE_SCOPED_ROLES);
    }

    // Time do usuário: o vinculado ao coach ou o do jogador vinculado ao player.
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

    /**
     * Ligas que delimitam o acesso do usuário: user_leagues para master/admin (e usuários
     * sem papel); para coach/player, as ligas do time vinculado (vazio sem vínculo).
     */
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

    /**
     * Usuários cujas ligas (mesma regra de leagueIds()) incluem ao menos uma de $leagueIds.
     */
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

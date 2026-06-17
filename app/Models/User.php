<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;
use Laravel\Sanctum\HasApiTokens;
use Spatie\Permission\Traits\HasRoles;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens, Notifiable, HasRoles;

    protected $fillable = [
        'name',
        'email',
        'document',
        'password',
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

    public function championships(): BelongsToMany
    {
        return $this->belongsToMany(Championship::class, 'user_championships');
    }

    public function isMaster(): bool
    {
        return $this->hasRole('master');
    }

    public function championshipIds(): array
    {
        return $this->championships()->pluck('championships.id')->toArray();
    }
}

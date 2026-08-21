<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    /**
     * Opções sugeridas para o campo `position` (texto livre, não enum — ver
     * PlayerStoreRequest/PlayerUpdateRequest), usadas para alimentar o select
     * de posição no admin.
     */
    public const POSITIONS = [
        ['value' => 'GOL', 'title' => 'Goleiro'],
        ['value' => 'ZAG', 'title' => 'Zagueiro'],
        ['value' => 'LAT', 'title' => 'Lateral'],
        ['value' => 'MEI', 'title' => 'Meio-Campo'],
        ['value' => 'ATA', 'title' => 'Atacante'],
    ];

    protected $fillable = [
        'team_id',
        'name',
        'position',
        'number',
        'birth_date',
        'document',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }

    public function events()
    {
        return $this->hasMany(GameEvent::class);
    }
}

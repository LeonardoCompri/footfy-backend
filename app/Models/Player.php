<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class Player extends Model
{
    use HasFactory;

    /**
     * Lista oficial de posições (coluna `position` é string no banco, sem enum).
     * Fonte única: alimenta o select do admin (GET /players/positions) e a
     * validação de PlayerStoreRequest/PlayerUpdateRequest.
     */
    public const POSITIONS = [
        ['value' => 'GOL', 'title' => 'Goleiro'],
        ['value' => 'ZAG', 'title' => 'Zagueiro'],
        ['value' => 'LAT', 'title' => 'Lateral'],
        ['value' => 'MEI', 'title' => 'Meio-Campo'],
        ['value' => 'ATA', 'title' => 'Atacante'],
    ];

    /**
     * Códigos aceitos no campo `position` (ex: ['GOL', 'ZAG', ...]).
     */
    public static function positionValues(): array
    {
        return array_column(self::POSITIONS, 'value');
    }

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

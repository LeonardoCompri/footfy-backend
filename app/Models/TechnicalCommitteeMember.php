<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalCommitteeMember extends Model
{
    use HasFactory;

    /**
     * Lista oficial de funções (coluna `role` é string no banco, sem enum).
     * Fonte única: alimenta o select do admin (GET /technical-committee-members/roles)
     * e a validação de TechnicalCommitteeMemberStoreRequest/UpdateRequest.
     */
    public const ROLES = [
        ['value' => 'Treinador', 'title' => 'Treinador'],
        ['value' => 'Auxiliar Técnico', 'title' => 'Auxiliar Técnico'],
        ['value' => 'Preparador Físico', 'title' => 'Preparador Físico'],
        ['value' => 'Preparador de Goleiros', 'title' => 'Preparador de Goleiros'],
        ['value' => 'Fisioterapeuta', 'title' => 'Fisioterapeuta'],
        ['value' => 'Médico', 'title' => 'Médico'],
        ['value' => 'Analista de Desempenho', 'title' => 'Analista de Desempenho'],
        ['value' => 'Roupeiro', 'title' => 'Roupeiro'],
    ];

    /**
     * Funções aceitas no campo `role` (ex: ['Treinador', 'Auxiliar Técnico', ...]).
     */
    public static function roleValues(): array
    {
        return array_column(self::ROLES, 'value');
    }

    protected $fillable = [
        'team_id',
        'name',
        'role',
        'birth_date',
    ];

    public function team()
    {
        return $this->belongsTo(Team::class);
    }
}

<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalCommitteeMember extends Model
{
    use HasFactory;

    /**
     * Opções sugeridas para o campo `role` (texto livre, não enum — ver
     * TechnicalCommitteeMemberStoreRequest/UpdateRequest), usadas para alimentar
     * o select de função no admin.
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

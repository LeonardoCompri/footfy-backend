<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;

class TechnicalCommitteeMember extends Model
{
    use HasFactory;

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

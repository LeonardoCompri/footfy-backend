<?php

use App\Models\Player;
use App\Models\TechnicalCommitteeMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Títulos/variações legadas de posição => código oficial (comparação
     * case-insensitive, com TRIM).
     */
    private const POSITION_MAP = [
        'goleiro' => 'GOL',
        'zagueiro' => 'ZAG',
        'lateral' => 'LAT',
        'meio-campo' => 'MEI',
        'meia' => 'MEI',
        'atacante' => 'ATA',
    ];

    /**
     * Funções legadas => função oficial. 'Treinador principal' veio da
     * migration 2026_06_19_000011 (coaches → technical_committee_members) e
     * 'Técnico' do TechnicalCommitteeMemberSeeder antigo.
     */
    private const ROLE_MAP = [
        'técnico' => 'Treinador',
        'treinador principal' => 'Treinador',
    ];

    /**
     * Normaliza players.position e technical_committee_members.role para as
     * listas oficiais (Player::POSITIONS / TechnicalCommitteeMember::ROLES),
     * agora validadas nos FormRequests. Não altera schema.
     */
    public function up(): void
    {
        $positionMap = self::POSITION_MAP;
        foreach (Player::positionValues() as $code) {
            $positionMap[mb_strtolower($code)] = $code;
        }

        foreach ($positionMap as $legacy => $code) {
            DB::table('players')
                ->whereRaw('LOWER(TRIM(position)) = ?', [$legacy])
                ->update(['position' => $code]);
        }

        // Qualquer posição que continue fora da lista oficial vira NULL (campo é opcional).
        DB::table('players')
            ->whereNotNull('position')
            ->whereNotIn('position', Player::positionValues())
            ->update(['position' => null]);

        $roleMap = self::ROLE_MAP;
        foreach (TechnicalCommitteeMember::roleValues() as $role) {
            $roleMap[mb_strtolower($role)] = $role;
        }

        // Roles fora da lista após o mapeamento ficam como estão: são aceitos no
        // update do próprio registro (ver TechnicalCommitteeMemberUpdateRequest).
        foreach ($roleMap as $legacy => $role) {
            DB::table('technical_committee_members')
                ->whereRaw('LOWER(TRIM(role)) = ?', [$legacy])
                ->update(['role' => $role]);
        }
    }

    /**
     * Irreversível: os valores originais não são guardados (vários títulos
     * mapeiam para o mesmo código e posições inválidas viraram NULL), então
     * não há como restaurá-los. O rollback não desfaz nada.
     */
    public function down(): void
    {
        //
    }
};

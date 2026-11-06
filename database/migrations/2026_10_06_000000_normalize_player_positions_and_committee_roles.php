<?php

use App\Models\Player;
use App\Models\TechnicalCommitteeMember;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const POSITION_MAP = [
        'goleiro' => 'GOL',
        'zagueiro' => 'ZAG',
        'lateral' => 'LAT',
        'meio-campo' => 'MEI',
        'meia' => 'MEI',
        'atacante' => 'ATA',
    ];

    private const ROLE_MAP = [
        'técnico' => 'Treinador',
        'treinador principal' => 'Treinador',
    ];

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

        DB::table('players')
            ->whereNotNull('position')
            ->whereNotIn('position', Player::positionValues())
            ->update(['position' => null]);

        $roleMap = self::ROLE_MAP;
        foreach (TechnicalCommitteeMember::roleValues() as $role) {
            $roleMap[mb_strtolower($role)] = $role;
        }

        foreach ($roleMap as $legacy => $role) {
            DB::table('technical_committee_members')
                ->whereRaw('LOWER(TRIM(role)) = ?', [$legacy])
                ->update(['role' => $role]);
        }
    }

    public function down(): void {}
};

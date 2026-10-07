<?php

namespace Tests\Feature;

use App\Models\Player;
use App\Models\TechnicalCommitteeMember;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class NormalizePositionsAndRolesMigrationTest extends TestCase
{
    use RefreshDatabase;

    private function runNormalization(): void
    {
        $migration = require database_path('migrations/2026_10_06_000000_normalize_player_positions_and_committee_roles.php');
        $migration->up();
    }

    private function insertPlayer(?string $position): int
    {
        return DB::table('players')->insertGetId([
            'name' => 'Jogador',
            'position' => $position,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    private function insertMember(string $role): int
    {
        return DB::table('technical_committee_members')->insertGetId([
            'name' => 'Membro',
            'role' => $role,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_maps_position_titles_to_codes_case_insensitively(): void
    {
        $cases = [
            'Goleiro' => 'GOL',
            'ZAGUEIRO' => 'ZAG',
            'lateral' => 'LAT',
            'Meio-Campo' => 'MEI',
            'meia' => 'MEI',
            ' Atacante ' => 'ATA',
            'ata' => 'ATA',
            'GOL' => 'GOL',
        ];
        $ids = [];
        foreach ($cases as $legacy => $expected) {
            $ids[$legacy] = $this->insertPlayer($legacy);
        }

        $this->runNormalization();

        foreach ($cases as $legacy => $expected) {
            $this->assertSame($expected, Player::find($ids[$legacy])->position, "Posição '{$legacy}'");
        }
    }

    public function test_unknown_positions_become_null(): void
    {
        $unknown = $this->insertPlayer('XXX');
        $null = $this->insertPlayer(null);

        $this->runNormalization();

        $this->assertNull(Player::find($unknown)->position);
        $this->assertNull(Player::find($null)->position);
    }

    public function test_maps_legacy_roles_to_official_ones(): void
    {
        $tecnico = $this->insertMember('Técnico');
        $principal = $this->insertMember('Treinador principal');
        $lowercase = $this->insertMember('Preparador físico');
        $official = $this->insertMember('Médico');

        $this->runNormalization();

        $this->assertSame('Treinador', TechnicalCommitteeMember::find($tecnico)->role);
        $this->assertSame('Treinador', TechnicalCommitteeMember::find($principal)->role);
        $this->assertSame('Preparador Físico', TechnicalCommitteeMember::find($lowercase)->role);
        $this->assertSame('Médico', TechnicalCommitteeMember::find($official)->role);
    }

    public function test_unknown_roles_are_kept(): void
    {
        $legacy = $this->insertMember('Massagista');

        $this->runNormalization();

        $this->assertSame('Massagista', TechnicalCommitteeMember::find($legacy)->role);
    }
}

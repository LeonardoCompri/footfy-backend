<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Liga stadiums à liga (edição/divisão concreta), mesmo nível de
     * teams/games, fechando o gap de autorização: hoje stadiums não tem
     * nenhuma relação com o resto do sistema. Coluna nasce nullable porque
     * stadiums já existentes precisam de um valor de backfill antes de a
     * coluna virar NOT NULL (próxima migration desta sequência). FK usa
     * cascadeOnDelete (mesmo padrão de games.league_id): a coluna vai se
     * tornar NOT NULL, e nullOnDelete/SET NULL é incompatível com isso.
     */
    public function up(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $firstLeagueId = DB::table('leagues')->orderBy('id')->value('id');
        $hasStadiumsWithoutLeague = DB::table('stadiums')->whereNull('league_id')->exists();

        if ($hasStadiumsWithoutLeague && ! $firstLeagueId) {
            throw new \RuntimeException(
                'Não é possível migrar stadiums: existem estádios cadastrados e nenhuma league para usar no backfill.'
            );
        }

        if ($hasStadiumsWithoutLeague) {
            DB::table('stadiums')->whereNull('league_id')->update(['league_id' => $firstLeagueId]);
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->dropForeign(['league_id']);
            $table->dropColumn('league_id');
        });
    }
};

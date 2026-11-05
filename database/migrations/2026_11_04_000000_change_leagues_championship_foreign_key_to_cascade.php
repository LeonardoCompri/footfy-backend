<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troca leagues.championship_id de nullOnDelete para cascadeOnDelete: apagar o campeonato
     * apaga as ligas e, pelas cascatas da liga, partidas, eventos, estádios, league_team e
     * user_leagues. Só muda a FK: ligas já sem campeonato (championship_id null) ficam como estão.
     */
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->nullOnDelete();
        });
    }
};

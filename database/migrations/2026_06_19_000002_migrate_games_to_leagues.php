<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Repointa games de championship_id para league_id (mesma FK numérica,
     * já que leagues preservou os IDs de championships). Índice em league_id
     * porque é o ponto de junção de estatísticas futuras por liga (Fase 2).
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->after('championship_id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE games SET league_id = championship_id');

        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
            $table->dropColumn('championship_id');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable(false)->change();
            $table->index('league_id');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('championship_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        DB::statement('UPDATE games SET championship_id = league_id');

        Schema::table('games', function (Blueprint $table) {
            $table->foreignId('championship_id')->nullable(false)->change();
            $table->dropForeign(['league_id']);
            $table->dropIndex(['league_id']);
            $table->dropColumn('league_id');
        });
    }
};

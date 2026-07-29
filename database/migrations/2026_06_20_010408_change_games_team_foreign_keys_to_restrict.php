<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troca home_team_id/away_team_id de cascadeOnDelete para restrictOnDelete:
     * hoje, apagar um Team apaga em cascata todas as partidas dele em qualquer
     * liga que já disputou (perda de histórico não intencional). Com restrict,
     * o banco rejeita o delete enquanto houver partidas vinculadas, e o time só
     * pode ser removido depois que isso for resolvido (ver TeamService::destroy).
     */
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['home_team_id']);
            $table->dropForeign(['away_team_id']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->foreign('home_team_id')->references('id')->on('teams')->restrictOnDelete();
            $table->foreign('away_team_id')->references('id')->on('teams')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropForeign(['home_team_id']);
            $table->dropForeign(['away_team_id']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->foreign('home_team_id')->references('id')->on('teams')->cascadeOnDelete();
            $table->foreign('away_team_id')->references('id')->on('teams')->cascadeOnDelete();
        });
    }
};

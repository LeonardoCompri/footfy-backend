<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Troca game_events.player_id de cascadeOnDelete para restrictOnDelete. Com o soft delete
     * de players, a exclusão pela API não apaga a linha; o restrict protege a súmula contra um
     * forceDelete ou delete direto no banco, que antes apagaria em cascata os eventos do jogador.
     * related_player_id continua nullOnDelete (perder só a assistência é aceitável).
     */
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->restrictOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->cascadeOnDelete();
        });
    }
};

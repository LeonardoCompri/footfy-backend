<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Exclusão de jogador vira soft delete: o registro continua no banco para a súmula
     * (game_events) e as estatísticas da liga seguirem mostrando quem foi removido.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->softDeletes();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropSoftDeletes();
        });
    }
};

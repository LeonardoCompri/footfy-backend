<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo 1:1 do usuário player com o cadastro de jogador: as ligas do player passam a
     * derivar do time desse jogador. Unique garante um usuário por jogador; nullable porque
     * a maioria dos jogadores não tem login. nullOnDelete: excluir o usuário mantém o jogador.
     */
    public function up(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->foreignId('user_id')->nullable()->unique()->after('team_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('players', function (Blueprint $table) {
            $table->dropForeign(['user_id']);
            $table->dropUnique(['user_id']);
            $table->dropColumn('user_id');
        });
    }
};

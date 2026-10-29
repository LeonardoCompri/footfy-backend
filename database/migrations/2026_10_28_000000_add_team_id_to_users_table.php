<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Vínculo 1:1 do usuário coach com o time que ele treina: as ligas do coach passam a
     * derivar desse time (não de user_leagues). Unique garante um coach por time; nullable
     * porque só coach tem time e os coaches existentes ficam sem vínculo até alguém
     * vinculá-los. nullOnDelete: excluir o time mantém o usuário, só sem vínculo.
     */
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->unique()->after('document')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign(['team_id']);
            $table->dropUnique(['team_id']);
            $table->dropColumn('team_id');
        });
    }
};

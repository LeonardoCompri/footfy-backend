<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Substitui championship_team por league_team, com unique constraint
     * [league_id, team_id] desde a criação (a tabela antiga não tinha esse
     * unique, permitindo duplicar o vínculo time-campeonato). Os dados são
     * copiados deduplicando por par, mantendo o menor id de cada duplicata.
     */
    public function up(): void
    {
        Schema::create('league_team', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->unique(['league_id', 'team_id']);
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO league_team (id, league_id, team_id, created_at, updated_at)
            SELECT MIN(id), championship_id, team_id, MIN(created_at), MIN(updated_at)
            FROM championship_team
            GROUP BY championship_id, team_id
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('league_team');
    }
};

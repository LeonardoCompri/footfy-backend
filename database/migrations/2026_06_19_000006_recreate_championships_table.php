<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Recria championships do zero como entidade leve (marca permanente,
     * ex: "Campeonato Brasileiro"): apenas id, name e timestamps. Os dados
     * antigos (com start_date/end_date/organizer) já foram preservados em
     * leagues na primeira migration desta sequência e não fazem sentido como
     * marca atemporal. A FK leagues.championship_id é removida antes do drop
     * (apontava para a tabela antiga) e recriada depois, contra a tabela nova.
     */
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::dropIfExists('championships');

        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::dropIfExists('championships');

        Schema::create('championships', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('organizer')->nullable();
            $table->timestamps();
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->nullOnDelete();
        });
    }
};

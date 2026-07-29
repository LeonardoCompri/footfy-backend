<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria game_events, tabela única para gols, cartões e assistências
     * (assume o papel que goals tinha, agora generalizado via coluna `type`).
     * league_id é denormalizado deliberadamente: o evento nunca muda de liga
     * depois de criado (o jogo ao qual pertence é imutável nesse aspecto), e
     * isso evita um JOIN em toda consulta agregada de estatística por liga
     * (artilheiro/cartões), que é a unidade natural de agregação no futebol.
     * Os índices abaixo cobrem as quatro dimensões de agregação previstas:
     * por jogo (timeline da partida), por jogador, por time e por liga.
     */
    public function up(): void
    {
        Schema::create('game_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('player_id')->constrained('players')->cascadeOnDelete();
            $table->foreignId('related_player_id')->nullable()->constrained('players')->nullOnDelete();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->string('type');
            $table->integer('minute')->nullable();
            $table->timestamps();

            $table->index(['game_id', 'type']);
            $table->index(['player_id', 'type']);
            $table->index(['team_id', 'type']);
            $table->index(['league_id', 'type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};

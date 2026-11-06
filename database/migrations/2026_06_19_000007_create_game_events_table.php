<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
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

    public function down(): void
    {
        Schema::dropIfExists('game_events');
    }
};

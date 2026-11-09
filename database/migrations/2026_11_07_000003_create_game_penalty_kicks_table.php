<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_penalty_kicks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('game_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->restrictOnDelete();
            $table->foreignId('player_id')->constrained('players')->restrictOnDelete();
            $table->unsignedTinyInteger('order');
            $table->boolean('scored');
            $table->timestamps();

            $table->unique(['game_id', 'order']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('game_penalty_kicks');
    }
};

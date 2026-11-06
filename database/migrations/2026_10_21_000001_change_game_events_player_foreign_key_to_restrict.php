<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->dropForeign(['player_id']);
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->foreign('player_id')->references('id')->on('players')->restrictOnDelete();
        });
    }

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

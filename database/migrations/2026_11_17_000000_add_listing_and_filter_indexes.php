<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->index('minute');
            $table->index(['league_id', 'minute']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->index(['league_id', 'status']);
            $table->index(['league_id', 'knockout_stage']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->index('name');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->index('name');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('players', function (Blueprint $table) {
            $table->dropIndex(['name']);
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['league_id', 'knockout_stage']);
            $table->dropIndex(['league_id', 'status']);
        });

        Schema::table('game_events', function (Blueprint $table) {
            $table->dropIndex(['league_id', 'minute']);
            $table->dropIndex(['minute']);
        });
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->index(['league_id', 'round', 'phase_order', 'match_date'], 'games_league_listing_index');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_league_id_index');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->index('league_id', 'games_league_id_index');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex('games_league_listing_index');
        });
    }
};

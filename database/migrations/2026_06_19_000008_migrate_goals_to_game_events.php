<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement('
            INSERT INTO game_events (game_id, team_id, player_id, related_player_id, league_id, type, minute, created_at, updated_at)
            SELECT goals.game_id, goals.team_id, goals.player_id, NULL, games.league_id, \'goal\', goals.minute, goals.created_at, goals.updated_at
            FROM goals
            INNER JOIN games ON games.id = goals.game_id
        ');
    }

    public function down(): void
    {
        DB::table('game_events')->where('type', 'goal')->delete();
    }
};

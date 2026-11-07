<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        $leagueIds = DB::table('leagues')
            ->where('fixture_mode', 'manual')
            ->where('groups_count', '>', 1)
            ->pluck('id');

        if ($leagueIds->isEmpty()) {
            return;
        }

        DB::table('league_team')->whereIn('league_id', $leagueIds)->update(['group_number' => null]);
        DB::table('leagues')->whereIn('id', $leagueIds)->update(['groups_count' => 1]);
    }

    public function down(): void {}
};

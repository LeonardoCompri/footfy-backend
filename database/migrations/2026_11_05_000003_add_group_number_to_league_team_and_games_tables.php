<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('league_team', function (Blueprint $table) {
            $table->unsignedTinyInteger('group_number')->nullable()->after('team_id');
        });

        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('group_number')->nullable()->after('round');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn('group_number');
        });

        Schema::table('league_team', function (Blueprint $table) {
            $table->dropColumn('group_number');
        });
    }
};

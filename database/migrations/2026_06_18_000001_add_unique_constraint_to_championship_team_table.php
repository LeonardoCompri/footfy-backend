<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('championship_team', function (Blueprint $table) {
            $table->unique(['championship_id', 'team_id']);
        });
    }

    public function down(): void
    {
        Schema::table('championship_team', function (Blueprint $table) {
            $table->dropUnique(['championship_id', 'team_id']);
        });
    }
};

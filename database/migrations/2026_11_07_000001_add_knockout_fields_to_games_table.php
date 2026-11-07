<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->unsignedTinyInteger('home_penalties')->nullable()->after('away_score');
            $table->unsignedTinyInteger('away_penalties')->nullable()->after('home_penalties');
            $table->string('knockout_stage', 3)->nullable()->after('leg');
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropColumn(['home_penalties', 'away_penalties', 'knockout_stage']);
        });
    }
};

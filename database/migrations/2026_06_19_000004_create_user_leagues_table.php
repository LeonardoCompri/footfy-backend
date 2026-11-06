<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_leagues', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'league_id']);
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO user_leagues (id, user_id, league_id, created_at, updated_at)
            SELECT id, user_id, championship_id, created_at, updated_at FROM user_championships
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('user_leagues');
    }
};

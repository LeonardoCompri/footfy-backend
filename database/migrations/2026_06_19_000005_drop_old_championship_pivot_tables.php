<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('championship_team');
        Schema::dropIfExists('user_championships');
    }

    public function down(): void
    {
        Schema::create('championship_team', function ($table) {
            $table->id();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Schema::create('user_championships', function ($table) {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('championship_id')->constrained()->cascadeOnDelete();
            $table->unique(['user_id', 'championship_id']);
            $table->timestamps();
        });
    }
};

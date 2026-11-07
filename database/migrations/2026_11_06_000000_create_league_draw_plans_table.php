<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('league_draw_plans', function (Blueprint $table) {
            $table->id();
            $table->foreignId('league_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('group_number');
            $table->unsignedTinyInteger('turn');
            $table->json('payload');
            $table->timestamps();

            $table->unique(['league_id', 'group_number', 'turn']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('league_draw_plans');
    }
};

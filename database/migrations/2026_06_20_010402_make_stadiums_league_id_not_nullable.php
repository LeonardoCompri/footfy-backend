<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Fecha o backfill da migration anterior: a essa altura todo stadium já
     * tem league_id preenchido, então a coluna pode se tornar obrigatória.
     */
    public function up(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable(false)->change();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->change();
        });
    }
};

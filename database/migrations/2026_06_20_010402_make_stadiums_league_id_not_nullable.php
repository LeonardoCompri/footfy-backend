<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->change();
        });
    }
};

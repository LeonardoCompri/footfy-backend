<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropForeign(['championship_id']);
        });

        Schema::table('leagues', function (Blueprint $table) {
            $table->foreign('championship_id')->references('id')->on('championships')->nullOnDelete();
        });
    }
};

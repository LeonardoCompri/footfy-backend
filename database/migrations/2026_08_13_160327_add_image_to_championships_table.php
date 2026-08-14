<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Adiciona a coluna image (caminho relativo no disco "public",
     * ex: "championships/abc123.jpg") ao campeonato.
     */
    public function up(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->string('image')->nullable()->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('championships', function (Blueprint $table) {
            $table->dropColumn('image');
        });
    }
};

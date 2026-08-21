<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->string('phase')->nullable()->after('round');
            $table->integer('phase_order')->nullable()->after('phase');
            $table->string('tie_key')->nullable()->after('phase_order');
            $table->unsignedTinyInteger('leg')->nullable()->after('tie_key');

            $table->index(['league_id', 'tie_key']);
        });
    }

    public function down(): void
    {
        Schema::table('games', function (Blueprint $table) {
            $table->dropIndex(['league_id', 'tie_key']);
            $table->dropColumn(['phase', 'phase_order', 'tie_key', 'leg']);
        });
    }
};

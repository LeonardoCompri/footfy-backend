<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const DEFAULTS = [
        'goal' => 'G',
        'own_goal' => 'GC',
        'yellow_card' => 'CA',
        'red_card' => 'CV',
    ];

    public function up(): void
    {
        Schema::table('game_event_types', function (Blueprint $table) {
            $table->string('abbreviation', 3)->nullable()->unique()->after('name');
        });

        foreach (self::DEFAULTS as $slug => $abbreviation) {
            DB::table('game_event_types')
                ->where('slug', $slug)
                ->whereNull('abbreviation')
                ->update(['abbreviation' => $abbreviation]);
        }
    }

    public function down(): void
    {
        Schema::table('game_event_types', function (Blueprint $table) {
            $table->dropUnique(['abbreviation']);
        });

        Schema::table('game_event_types', function (Blueprint $table) {
            $table->dropColumn('abbreviation');
        });
    }
};

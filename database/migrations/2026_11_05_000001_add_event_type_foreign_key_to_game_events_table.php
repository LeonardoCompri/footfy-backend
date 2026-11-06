<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        $known = DB::table('game_event_types')->pluck('slug')->all();
        $legacy = DB::table('game_events')
            ->whereNotIn('type', $known)
            ->distinct()
            ->orderBy('type')
            ->pluck('type');

        $sortOrder = (int) DB::table('game_event_types')->max('sort_order');
        $now = now();

        foreach ($legacy as $slug) {
            DB::table('game_event_types')->insert([
                'slug' => $slug,
                'name' => $slug,
                'icon' => 'mdi-help-circle-outline',
                'color' => 'grey',
                'counts_for' => null,
                'allows_assist' => false,
                'card' => null,
                'sort_order' => ++$sortOrder,
                'active' => false,
                'created_at' => $now,
                'updated_at' => $now,
            ]);
        }

        Schema::table('game_events', function (Blueprint $table) {
            $table->foreign('type')->references('slug')->on('game_event_types')->restrictOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('game_events', function (Blueprint $table) {
            $table->dropForeign(['type']);
        });
    }
};

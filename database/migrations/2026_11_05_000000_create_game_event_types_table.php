<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('game_event_types', function (Blueprint $table) {
            $table->id();
            $table->string('slug', 50)->unique();
            $table->string('name', 60);
            $table->string('icon', 60);
            $table->string('color', 30);
            $table->string('counts_for')->nullable();
            $table->boolean('allows_assist')->default(false);
            $table->string('card')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->boolean('active')->default(true);
            $table->timestamps();
        });

        $now = now();
        $defaults = [
            ['goal', 'Gol', 'mdi-soccer', 'primary', 'team', true, null],
            ['own_goal', 'Gol contra', 'mdi-soccer', 'error', 'opponent', false, null],
            ['yellow_card', 'Cartão amarelo', 'mdi-card', 'card-yellow', null, false, 'yellow'],
            ['red_card', 'Cartão vermelho', 'mdi-card', 'card-red', null, false, 'red'],
        ];

        DB::table('game_event_types')->insert(array_map(
            fn (array $type, int $index) => [
                'slug' => $type[0],
                'name' => $type[1],
                'icon' => $type[2],
                'color' => $type[3],
                'counts_for' => $type[4],
                'allows_assist' => $type[5],
                'card' => $type[6],
                'sort_order' => $index + 1,
                'active' => true,
                'created_at' => $now,
                'updated_at' => $now,
            ],
            $defaults,
            array_keys($defaults),
        ));
    }

    public function down(): void
    {
        Schema::dropIfExists('game_event_types');
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->string('fixture_mode')->default('manual')->after('format');
            $table->unsignedTinyInteger('groups_count')->default(1)->after('fixture_mode');
            $table->unsignedTinyInteger('group_legs')->default(1)->after('groups_count');
            $table->unsignedTinyInteger('qualifiers_per_group')->nullable()->after('group_legs');
            $table->unsignedTinyInteger('knockout_size')->nullable()->after('qualifiers_per_group');
            $table->unsignedTinyInteger('knockout_legs')->nullable()->after('knockout_size');
            $table->unsignedTinyInteger('final_legs')->nullable()->after('knockout_legs');
            $table->boolean('third_place')->default(false)->after('final_legs');
            $table->json('tiebreakers')->nullable()->after('third_place');
            $table->json('knockout_tiebreakers')->nullable()->after('tiebreakers');
        });
    }

    public function down(): void
    {
        Schema::table('leagues', function (Blueprint $table) {
            $table->dropColumn([
                'fixture_mode',
                'groups_count',
                'group_legs',
                'qualifiers_per_group',
                'knockout_size',
                'knockout_legs',
                'final_legs',
                'third_place',
                'tiebreakers',
                'knockout_tiebreakers',
            ]);
        });
    }
};

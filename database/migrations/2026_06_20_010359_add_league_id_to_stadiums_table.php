<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->foreignId('league_id')->nullable()->after('id')->constrained()->cascadeOnDelete();
        });

        $firstLeagueId = DB::table('leagues')->orderBy('id')->value('id');
        $hasStadiumsWithoutLeague = DB::table('stadiums')->whereNull('league_id')->exists();

        if ($hasStadiumsWithoutLeague && ! $firstLeagueId) {
            throw new \RuntimeException(
                'Não é possível migrar stadiums: existem estádios cadastrados e nenhuma league para usar no backfill.'
            );
        }

        if ($hasStadiumsWithoutLeague) {
            DB::table('stadiums')->whereNull('league_id')->update(['league_id' => $firstLeagueId]);
        }
    }

    public function down(): void
    {
        Schema::table('stadiums', function (Blueprint $table) {
            $table->dropForeign(['league_id']);
            $table->dropColumn('league_id');
        });
    }
};

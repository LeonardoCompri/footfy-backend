<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            INSERT INTO technical_committee_members (team_id, name, role, birth_date, created_at, updated_at)
            SELECT team_id, name, 'Treinador principal', birth_date, created_at, updated_at
            FROM coaches
        ");
    }

    public function down(): void
    {
        DB::table('technical_committee_members')->where('role', 'Treinador principal')->delete();
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('leagues', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('start_date')->nullable();
            $table->date('end_date')->nullable();
            $table->string('organizer')->nullable();
            $table->string('season')->nullable();
            $table->foreignId('championship_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamps();
        });

        DB::statement('
            INSERT INTO leagues (id, name, start_date, end_date, organizer, created_at, updated_at)
            SELECT id, name, start_date, end_date, organizer, created_at, updated_at FROM championships
        ');
    }

    public function down(): void
    {
        Schema::dropIfExists('leagues');
    }
};

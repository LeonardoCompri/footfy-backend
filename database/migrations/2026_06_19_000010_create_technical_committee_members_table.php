<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Cria technical_committee_members, substituindo coaches: agora um
     * time pode ter vários membros na comissão técnica, cada um com uma
     * função livre (treinador, auxiliar, preparador físico etc.), em vez
     * de um único técnico por time.
     */
    public function up(): void
    {
        Schema::create('technical_committee_members', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->string('name');
            $table->string('role');
            $table->date('birth_date')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('technical_committee_members');
    }
};

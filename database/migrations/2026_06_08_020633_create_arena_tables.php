<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::create('arena_rankings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->integer('rank')->unique();
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->timestamps();
        });

        Schema::create('arena_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('defender_id')->constrained('characters')->cascadeOnDelete();
            $table->boolean('is_attacker_win');
            $table->integer('attacker_old_rank');
            $table->integer('attacker_new_rank');
            $table->integer('defender_old_rank');
            $table->integer('defender_new_rank');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('arena_logs');
        Schema::dropIfExists('arena_rankings');
    }
};

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
        Schema::create('character_area_progresses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_unlocked')->default(false);
            $table->boolean('boss_defeated')->default(false);
            $table->dateTime('unlocked_at')->nullable();
            $table->dateTime('boss_defeated_at')->nullable();
            $table->timestamps();
            
            $table->unique(['character_id', 'area_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_area_progresses');
    }
};

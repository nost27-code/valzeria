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
        Schema::create('battle_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('area_id')->constrained()->cascadeOnDelete();
            $table->foreignId('enemy_id')->constrained()->cascadeOnDelete();
            $table->string('battle_type', 20)->default('normal'); // normal or boss
            $table->string('result', 10); // win or lose
            $table->unsignedInteger('exp_gained')->default(0);
            $table->unsignedInteger('gold_gained')->default(0);
            $table->unsignedInteger('level_up_count')->default(0);
            $table->text('log_text');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('battle_logs');
    }
};

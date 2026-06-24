<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('character_job_art_slots')) {
            return;
        }

        Schema::create('character_job_art_slots', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_no');
            $table->foreignId('skill_id')->constrained('skills')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['character_id', 'slot_no']);
            $table->unique(['character_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('character_job_art_slots');
    }
};

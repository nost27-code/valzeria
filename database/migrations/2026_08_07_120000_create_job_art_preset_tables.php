<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_art_presets', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('name', 20);
            $table->foreignId('current_job_id')->constrained('job_classes')->restrictOnDelete();
            $table->string('source_context', 20)->nullable();
            $table->timestamps();

            $table->index(['character_id', 'current_job_id']);
        });

        Schema::create('job_art_preset_slots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('job_art_preset_id')->constrained('job_art_presets')->cascadeOnDelete();
            $table->unsignedTinyInteger('slot_no');
            $table->foreignId('skill_id')->constrained('skills')->restrictOnDelete();
            $table->string('activation_policy', 20)->default('normal');
            $table->timestamps();

            $table->unique(['job_art_preset_id', 'slot_no']);
            $table->unique(['job_art_preset_id', 'skill_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_art_preset_slots');
        Schema::dropIfExists('job_art_presets');
    }
};

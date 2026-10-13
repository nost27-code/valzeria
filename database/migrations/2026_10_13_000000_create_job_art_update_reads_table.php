<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('job_art_update_reads', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('skill_id')->constrained()->cascadeOnDelete();
            $table->string('revision', 64);
            $table->timestamp('read_at');
            $table->unique(['user_id', 'skill_id', 'revision'], 'job_art_update_reads_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('job_art_update_reads');
    }
};

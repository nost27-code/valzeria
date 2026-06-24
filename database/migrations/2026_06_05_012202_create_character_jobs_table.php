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
        Schema::create('character_jobs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->foreignId('job_class_id')->constrained('job_classes')->cascadeOnDelete();
            
            $table->unsignedTinyInteger('job_level')->default(1);
            $table->unsignedInteger('job_exp')->default(0);
            $table->boolean('is_mastered')->default(false);
            $table->timestamp('mastered_at')->nullable();
            
            $table->timestamps();
            
            $table->unique(['character_id', 'job_class_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('character_jobs');
    }
};

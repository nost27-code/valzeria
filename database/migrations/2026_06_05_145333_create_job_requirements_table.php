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
        Schema::create('job_requirements', function (Blueprint $table) {
            $table->id();
            $table->foreignId('job_id')->constrained('job_classes')->cascadeOnDelete();
            $table->enum('requirement_type', [
                'master_job',
                'character_level',
                'title',
                'item',
                'quest',
                'event_flag'
            ]);
            $table->foreignId('required_job_id')->nullable()->constrained('job_classes')->nullOnDelete();
            $table->unsignedInteger('required_value')->nullable();
            $table->string('required_key')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_requirements');
    }
};

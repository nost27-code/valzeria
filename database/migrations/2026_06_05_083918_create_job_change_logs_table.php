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
        Schema::create('job_change_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedBigInteger('from_job_id');
            $table->unsignedBigInteger('to_job_id');
            $table->unsignedInteger('before_level');
            $table->unsignedInteger('reincarnation_count_before');
            $table->unsignedInteger('reincarnation_count_after');
            $table->unsignedInteger('before_max_hp');
            $table->unsignedInteger('before_str');
            $table->unsignedInteger('before_def');
            $table->unsignedInteger('before_agi');
            $table->unsignedInteger('before_mag');
            $table->unsignedInteger('before_luk');
            $table->unsignedInteger('after_max_hp');
            $table->unsignedInteger('after_str');
            $table->unsignedInteger('after_def');
            $table->unsignedInteger('after_agi');
            $table->unsignedInteger('after_mag');
            $table->unsignedInteger('after_luk');
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('job_change_logs');
    }
};

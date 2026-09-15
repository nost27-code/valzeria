<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gameplay_job_art_activation_rollups', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('bucket_started_at');
            $table->string('context', 40);
            $table->unsignedBigInteger('current_job_id')->default(0);
            $table->string('level_band', 20)->default('unknown');
            $table->unsignedBigInteger('skill_id');
            $table->string('skill_name');
            $table->string('current_lineage', 20)->default('unknown');
            $table->string('skill_lineage', 20)->default('unknown');
            $table->string('lineage_relation', 16)->default('unknown');
            $table->unsignedTinyInteger('effective_rate');
            $table->unsignedTinyInteger('miss_margin');
            $table->unsignedBigInteger('attempts')->default(0);
            $table->timestamps();

            $table->unique(
                [
                    'bucket_started_at',
                    'context',
                    'current_job_id',
                    'level_band',
                    'skill_id',
                    'current_lineage',
                    'skill_lineage',
                    'effective_rate',
                    'miss_margin',
                ],
                'job_art_activation_dimension_unique',
            );
            $table->index(['context', 'bucket_started_at'], 'job_art_activation_context_bucket');
            $table->index(['current_job_id', 'bucket_started_at'], 'job_art_activation_job_bucket');
            $table->index(['skill_id', 'bucket_started_at'], 'job_art_activation_skill_bucket');
            $table->index(
                ['lineage_relation', 'miss_margin', 'bucket_started_at'],
                'job_art_activation_margin_bucket',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameplay_job_art_activation_rollups');
    }
};

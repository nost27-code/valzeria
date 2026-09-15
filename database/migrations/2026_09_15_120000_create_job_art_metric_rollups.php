<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('gameplay_job_art_rollups', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('bucket_started_at');
            $table->string('context', 40);
            $table->unsignedBigInteger('current_job_id')->default(0);
            $table->string('level_band', 20)->default('unknown');
            $table->char('loadout_signature', 64);
            $table->json('loadout');
            $table->unsignedBigInteger('battles')->default(0);
            $table->unsignedBigInteger('art_battles')->default(0);
            $table->unsignedBigInteger('wins')->default(0);
            $table->unsignedBigInteger('art_wins')->default(0);
            $table->unsignedBigInteger('turns')->default(0);
            $table->unsignedBigInteger('activations')->default(0);
            $table->unsignedBigInteger('hp_recovered')->default(0);
            $table->unsignedBigInteger('sp_recovered')->default(0);
            $table->timestamps();

            $table->unique(
                ['bucket_started_at', 'context', 'current_job_id', 'level_band', 'loadout_signature'],
                'gameplay_job_art_rollups_dimension_unique',
            );
            $table->index(['context', 'bucket_started_at'], 'gameplay_job_art_rollups_context_bucket');
            $table->index(['current_job_id', 'bucket_started_at'], 'gameplay_job_art_rollups_job_bucket');
            $table->index(['level_band', 'bucket_started_at'], 'gameplay_job_art_rollups_level_bucket');
        });

        Schema::create('gameplay_job_art_skill_rollups', function (Blueprint $table): void {
            $table->id();
            $table->dateTime('bucket_started_at');
            $table->string('context', 40);
            $table->unsignedBigInteger('current_job_id')->default(0);
            $table->string('level_band', 20)->default('unknown');
            $table->unsignedBigInteger('skill_id');
            $table->string('skill_name');
            $table->unsignedBigInteger('battles')->default(0);
            $table->unsignedBigInteger('wins')->default(0);
            $table->unsignedBigInteger('turns')->default(0);
            $table->unsignedBigInteger('activations')->default(0);
            $table->unsignedBigInteger('hits')->default(0);
            $table->unsignedBigInteger('misses')->default(0);
            $table->unsignedBigInteger('evades')->default(0);
            $table->unsignedBigInteger('no_resolution')->default(0);
            $table->unsignedBigInteger('vital_hits')->default(0);
            $table->unsignedBigInteger('hp_recovered')->default(0);
            $table->unsignedBigInteger('sp_recovered')->default(0);
            $table->timestamps();

            $table->unique(
                ['bucket_started_at', 'context', 'current_job_id', 'level_band', 'skill_id'],
                'gameplay_job_art_skill_rollups_dimension_unique',
            );
            $table->index(['skill_id', 'bucket_started_at'], 'gameplay_job_art_skill_rollups_skill_bucket');
            $table->index(['context', 'bucket_started_at'], 'gameplay_job_art_skill_rollups_context_bucket');
            $table->index(['current_job_id', 'bucket_started_at'], 'gameplay_job_art_skill_rollups_job_bucket');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('gameplay_job_art_skill_rollups');
        Schema::dropIfExists('gameplay_job_art_rollups');
    }
};

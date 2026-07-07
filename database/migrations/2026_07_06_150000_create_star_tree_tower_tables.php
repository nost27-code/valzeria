<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('tower_runs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->string('season_key', 20);
            $table->string('status', 30)->default('running');
            $table->unsignedInteger('current_floor')->default(1);
            $table->unsignedInteger('reached_floor')->default(1);
            $table->unsignedInteger('cleared_floor')->default(0);
            $table->unsignedInteger('failed_floor')->nullable();
            $table->unsignedInteger('tower_max_hp')->default(0);
            $table->unsignedInteger('tower_current_hp')->default(0);
            $table->unsignedInteger('tower_max_mp')->default(0);
            $table->unsignedInteger('tower_current_mp')->default(0);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_losses')->default(0);
            $table->unsignedInteger('merchant_encounter_count')->default(0);
            $table->unsignedInteger('last_merchant_floor')->nullable();
            $table->string('pending_event', 50)->nullable();
            $table->unsignedInteger('gold_spent')->default(0);
            $table->unsignedInteger('stamina_spent')->default(0);
            $table->string('last_event_type', 50)->nullable();
            $table->dateTime('started_at');
            $table->dateTime('ended_at')->nullable();
            $table->timestamps();

            $table->index(['character_id', 'status'], 'tower_runs_character_status_idx');
            $table->index(['tower_key', 'season_key'], 'tower_runs_tower_season_idx');
            $table->index('cleared_floor', 'tower_runs_cleared_floor_idx');
        });

        Schema::create('tower_run_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tower_run_id')->constrained('tower_runs')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('floor');
            $table->string('event_type', 50);
            $table->string('result', 50)->nullable();
            $table->string('enemy_name', 100)->nullable();
            $table->string('enemy_profile', 50)->nullable();
            $table->unsignedInteger('damage_taken')->default(0);
            $table->unsignedInteger('hp_after')->nullable();
            $table->unsignedInteger('mp_after')->nullable();
            $table->integer('gold_delta')->default(0);
            $table->unsignedInteger('stamina_delta')->default(0);
            $table->unsignedInteger('exp_gained')->default(0);
            $table->unsignedInteger('job_exp_gained')->default(0);
            $table->text('message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['tower_run_id', 'floor'], 'tower_run_events_run_floor_idx');
            $table->index('character_id', 'tower_run_events_character_idx');
            $table->index('event_type', 'tower_run_events_type_idx');
        });

        Schema::create('tower_weekly_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->string('season_key', 20);
            $table->unsignedInteger('best_cleared_floor')->default(0);
            $table->unsignedInteger('best_failed_floor')->nullable();
            $table->foreignId('best_run_id')->nullable()->constrained('tower_runs')->nullOnDelete();
            $table->dateTime('achieved_at')->nullable();
            $table->timestamps();

            $table->unique(['character_id', 'tower_key', 'season_key'], 'tower_weekly_character_season_unique');
            $table->index(['tower_key', 'season_key', 'best_cleared_floor'], 'tower_weekly_season_floor_idx');
        });

        Schema::create('tower_character_records', function (Blueprint $table) {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->unsignedInteger('best_cleared_floor')->default(0);
            $table->unsignedInteger('best_failed_floor')->nullable();
            $table->foreignId('best_run_id')->nullable()->constrained('tower_runs')->nullOnDelete();
            $table->dateTime('achieved_at')->nullable();
            $table->unsignedInteger('total_runs')->default(0);
            $table->unsignedInteger('total_wins')->default(0);
            $table->unsignedInteger('total_defeats')->default(0);
            $table->unsignedInteger('total_returns')->default(0);
            $table->timestamps();

            $table->unique(['character_id', 'tower_key'], 'tower_character_record_unique');
            $table->index(['tower_key', 'best_cleared_floor'], 'tower_character_best_floor_idx');
        });

        Schema::create('tower_floor_master', function (Blueprint $table) {
            $table->id();
            $table->string('tower_key', 100)->default('star_tree_tower');
            $table->unsignedInteger('floor');
            $table->string('layer_key', 100);
            $table->string('layer_name', 100);
            $table->string('enemy_name', 100);
            $table->string('enemy_profile', 50)->default('physical');
            $table->string('enemy_type_name', 50)->nullable();
            $table->unsignedInteger('stamina_cost')->default(1);
            $table->integer('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tower_key', 'floor'], 'tower_floor_unique');
            $table->index(['tower_key', 'layer_key'], 'tower_floor_layer_idx');
            $table->index(['tower_key', 'is_active'], 'tower_floor_active_idx');
        });

        Schema::create('tower_merchant_purchases', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tower_run_id')->constrained('tower_runs')->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('floor');
            $table->string('item_key', 100);
            $table->string('item_name', 100);
            $table->unsignedInteger('price');
            $table->string('effect_type', 50);
            $table->unsignedInteger('effect_value');
            $table->timestamps();

            $table->index(['tower_run_id', 'floor'], 'tower_merchant_run_floor_idx');
            $table->index('character_id', 'tower_merchant_character_idx');
            $table->index('item_key', 'tower_merchant_item_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('tower_merchant_purchases');
        Schema::dropIfExists('tower_floor_master');
        Schema::dropIfExists('tower_character_records');
        Schema::dropIfExists('tower_weekly_records');
        Schema::dropIfExists('tower_run_events');
        Schema::dropIfExists('tower_runs');
    }
};

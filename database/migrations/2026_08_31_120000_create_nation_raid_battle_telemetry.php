<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * 国家対抗レイドの1出撃1行テレメトリ。
 * 手番ごとのINSERTを避け、詳細は最大20ターンのJSON snapshotへ集約する。
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('nation_raid_battle_telemetry')) {
            return;
        }

        Schema::create('nation_raid_battle_telemetry', function (Blueprint $table): void {
            $table->id();
            $table->char('battle_token_hash', 64)->unique('nrbt_token_hash_unique');
            $table->string('event_key', 64);
            $table->string('telemetry_schema_version', 16)->default('1.0');
            $table->string('ruleset_version', 32);
            $table->unsignedTinyInteger('raid_day')->default(1);
            $table->unsignedTinyInteger('day_sortie_no')->default(1);
            $table->unsignedTinyInteger('event_sortie_no')->default(1);
            $table->unsignedInteger('boss_cycle_no')->default(1);

            $table->foreignId('character_id')
                ->nullable()
                ->constrained('characters')
                ->nullOnDelete();
            $table->foreignId('nation_id')
                ->nullable()
                ->constrained('nations')
                ->nullOnDelete();
            $table->boolean('is_nation_eligible')->default(false);
            $table->unsignedSmallInteger('nation_active_count')->default(0);

            $table->unsignedTinyInteger('player_level')->default(1);
            $table->unsignedBigInteger('player_job_id')->nullable();
            $table->unsignedBigInteger('player_power')->nullable();
            $table->string('boss_phase', 32);
            $table->string('adaptive_lineage', 16)->nullable();
            $table->string('result_status', 16);
            $table->string('end_reason', 32);
            $table->unsignedTinyInteger('turn_count')->default(0);
            $table->boolean('reached_turn_twenty')->default(false);

            $table->unsignedBigInteger('boss_hp_before')->default(0);
            $table->unsignedBigInteger('boss_hp_after')->default(0);
            $table->unsignedBigInteger('calculated_damage_total')->default(0);
            $table->unsignedBigInteger('applied_damage_total')->default(0);
            $table->unsignedBigInteger('max_action_damage')->default(0);
            $table->unsignedBigInteger('damage_taken_total')->default(0);
            $table->unsignedBigInteger('healing_total')->default(0);
            $table->decimal('player_hp_ratio_end', 9, 8)->nullable();
            $table->unsignedInteger('duration_ms')->nullable();
            $table->dateTime('battle_started_at')->nullable();
            $table->dateTime('battle_resolved_at')->nullable();

            $table->json('loadout_lineages')->nullable();
            $table->json('loadout_snapshot')->nullable();
            $table->json('damage_by_source')->nullable();
            $table->json('counterplay_metrics')->nullable();
            $table->json('turns')->nullable();
            $table->json('event_snapshot')->nullable();
            $table->json('player_snapshot')->nullable();
            $table->json('quality_flags')->nullable();
            $table->timestamps();

            $table->index(['event_key', 'created_at'], 'nrbt_event_created_idx');
            $table->index(['event_key', 'raid_day'], 'nrbt_event_day_idx');
            $table->index(['event_key', 'boss_phase'], 'nrbt_event_phase_idx');
            $table->index(['event_key', 'adaptive_lineage'], 'nrbt_event_lineage_idx');
            $table->index(['event_key', 'result_status'], 'nrbt_event_status_idx');
            $table->index(['event_key', 'character_id'], 'nrbt_event_character_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('nation_raid_battle_telemetry');
    }
};

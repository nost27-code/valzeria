<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('competition_event_coordinators', function (Blueprint $table): void {
            $table->id();
            $table->string('slot_key', 40)->unique('competition_coordinator_slot_unique');
            $table->string('active_type', 32)->nullable();
            $table->unsignedBigInteger('active_reference_id')->nullable();
            $table->dateTime('reserved_from')->nullable();
            $table->dateTime('reserved_until')->nullable();
            $table->unsignedBigInteger('lock_version')->default(0);
            $table->timestamps();
        });

        DB::table('competition_event_coordinators')->insert([
            'slot_key' => 'global_competition',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        Schema::create('nation_raid_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_key', 64)->unique('nation_raid_event_key_unique');
            $table->string('name', 80);
            $table->string('boss_name', 80);
            $table->string('status', 24)->default('draft');
            $table->dateTime('announced_at')->nullable();
            $table->dateTime('starts_at');
            $table->dateTime('ends_at');
            $table->dateTime('activated_at')->nullable();
            $table->dateTime('stage10_reached_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('sorties_paused_at')->nullable();
            $table->string('sorties_pause_reason', 160)->nullable();
            $table->dateTime('finalization_started_at')->nullable();
            $table->dateTime('finalized_at')->nullable();
            $table->dateTime('cancelled_at')->nullable();
            $table->unsignedTinyInteger('stage_count');
            $table->unsignedBigInteger('cycle_max_hp');
            $table->unsignedBigInteger('total_target_hp');
            $table->unsignedInteger('current_cycle_no')->default(0);
            $table->unsignedInteger('echo_defeated_count')->default(0);
            $table->string('ruleset_version', 48);
            $table->char('ruleset_hash', 64);
            $table->json('ruleset_snapshot');
            $table->json('published_nation_counts_snapshot')->nullable();
            $table->dateTime('balance_approved_at')->nullable();
            $table->foreignId('balance_approved_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('balance_approval_reference', 255)->nullable();
            $table->unsignedBigInteger('state_version')->default(0);
            $table->timestamps();

            $table->index(['status', 'starts_at', 'ends_at'], 'nation_raid_event_window_idx');
            $table->index(['ruleset_hash', 'status'], 'nation_raid_ruleset_status_idx');
        });

        Schema::create('nation_raid_boss_cycles', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedInteger('cycle_no');
            $table->string('cycle_kind', 16);
            $table->unsignedTinyInteger('stage_no')->nullable();
            $table->unsignedInteger('echo_no')->nullable();
            $table->unsignedBigInteger('max_hp');
            $table->unsignedBigInteger('current_hp');
            $table->string('current_form', 32);
            $table->string('boss_species_key', 32);
            $table->json('parameter_snapshot');
            $table->dateTime('started_at');
            $table->dateTime('defeated_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'cycle_no'], 'nation_raid_cycle_no_unique');
            $table->unique(['event_id', 'stage_no'], 'nation_raid_stage_no_unique');
            $table->unique(['event_id', 'echo_no'], 'nation_raid_echo_no_unique');
            $table->index(['event_id', 'defeated_at', 'cycle_no'], 'nation_raid_cycle_progress_idx');
        });

        Schema::create('nation_raid_participations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->boolean('is_nation_eligible')->default(false);
            $table->boolean('is_late_entry')->default(false);
            $table->unsignedSmallInteger('published_active_count')->default(0);
            $table->unsignedSmallInteger('started_active_count')->default(0);
            $table->unsignedSmallInteger('reference_active_count')->default(0);
            $table->string('character_name_snapshot', 80);
            $table->string('nation_name_snapshot', 80)->nullable();
            $table->unsignedTinyInteger('resolved_sorties')->default(0);
            $table->unsignedBigInteger('personal_damage_total')->default(0);
            $table->unsignedBigInteger('max_action_damage')->default(0);
            $table->dateTime('first_resolved_at')->nullable();
            $table->dateTime('last_resolved_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'account_id'], 'nation_raid_participation_account_unique');
            $table->index(['event_id', 'character_id'], 'nation_raid_participation_character_idx');
            $table->index(['event_id', 'nation_id', 'is_nation_eligible'], 'nation_raid_participation_nation_idx');
            $table->index(['event_id', 'personal_damage_total'], 'nation_raid_personal_damage_idx');
            $table->index(['event_id', 'max_action_damage'], 'nation_raid_max_action_idx');
        });

        Schema::create('nation_raid_daily_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('participation_id')->constrained('nation_raid_participations')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id');
            $table->unsignedTinyInteger('raid_day');
            $table->unsignedTinyInteger('used_count')->default(0);
            $table->unsignedTinyInteger('resolved_count')->default(0);
            $table->unsignedTinyInteger('refunded_count')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'account_id', 'raid_day'], 'nation_raid_daily_usage_unique');
            $table->index(['event_id', 'participation_id', 'raid_day'], 'nation_raid_daily_participant_idx');
        });

        Schema::create('nation_raid_battle_results', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('participation_id')->constrained('nation_raid_participations')->cascadeOnDelete();
            $table->char('battle_token', 64)->unique('nation_raid_battle_token_unique');
            $table->char('sortie_seed', 64);
            $table->string('status', 16)->default('started');
            $table->unsignedBigInteger('account_id');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->unsignedTinyInteger('raid_day');
            $table->unsignedTinyInteger('day_sortie_no');
            $table->unsignedTinyInteger('event_sortie_no');
            $table->unsignedInteger('target_cycle_no');
            $table->string('target_cycle_kind', 16);
            $table->unsignedTinyInteger('target_stage_no')->nullable();
            $table->unsignedInteger('target_echo_no')->nullable();
            $table->string('target_form', 32);
            $table->json('target_parameter_snapshot');
            $table->string('boss_species_key', 32);
            $table->string('strategy', 16);
            $table->string('dominant_lineage', 24)->nullable();
            $table->decimal('killer_raw_rate', 9, 8)->default(0);
            $table->decimal('killer_effective_rate', 9, 8)->default(0);
            $table->unsignedTinyInteger('turn_count')->default(0);
            $table->string('end_reason', 32)->nullable();
            $table->unsignedBigInteger('calculated_damage_total')->default(0);
            $table->unsignedBigInteger('applied_damage_total')->default(0);
            $table->unsignedBigInteger('coordination_damage_total')->default(0);
            $table->unsignedBigInteger('nation_damage_total')->default(0);
            $table->unsignedBigInteger('max_action_damage')->default(0);
            $table->json('job_art_slots_snapshot')->nullable();
            $table->json('turn_log')->nullable();
            $table->json('damage_segments')->nullable();
            $table->json('summary')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->unsignedTinyInteger('settlement_attempts')->default(0);
            $table->char('refund_key', 64)->nullable()->unique('nation_raid_refund_key_unique');
            $table->dateTime('started_at');
            $table->dateTime('resolution_deadline_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('aborted_at')->nullable();
            $table->dateTime('refunded_at')->nullable();
            $table->timestamps();

            $table->index(['event_id', 'status', 'started_at'], 'nation_raid_battle_status_idx');
            $table->index(['event_id', 'character_id', 'resolved_at'], 'nation_raid_battle_character_idx');
            $table->index(['event_id', 'nation_id', 'resolved_at'], 'nation_raid_battle_nation_idx');
            $table->index(['event_id', 'raid_day', 'status'], 'nation_raid_battle_day_idx');
        });

        Schema::create('nation_raid_daily_lineage_snapshots', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedTinyInteger('raid_day');
            $table->string('selected_lineage', 24)->nullable();
            $table->char('tie_break_seed', 64);
            $table->json('adopted_sets_snapshot')->nullable();
            $table->json('vote_counts')->nullable();
            $table->json('votes_snapshot')->nullable();
            $table->dateTime('determined_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'raid_day'], 'nation_raid_daily_lineage_unique');
        });

        Schema::create('nation_raid_coordination_participants', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('participation_id')->constrained('nation_raid_participations')->cascadeOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->unsignedBigInteger('character_id_snapshot');
            $table->dateTime('window_joined_at');
            $table->dateTime('last_resolved_at');
            $table->timestamps();

            $table->unique(
                ['event_id', 'nation_id_snapshot', 'character_id_snapshot'],
                'nation_raid_coordination_member_unique',
            );
            $table->index(
                ['event_id', 'nation_id_snapshot', 'window_joined_at'],
                'nation_raid_coordination_window_idx',
            );
        });

        Schema::create('nation_raid_personal_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id_snapshot');
            $table->unsignedBigInteger('character_id_snapshot');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->string('reward_key', 64);
            $table->string('status', 16)->default('pending');
            $table->string('selection_key', 64)->nullable();
            $table->json('reward_snapshot');
            $table->json('balance_after_snapshot')->nullable();
            $table->char('idempotency_key', 64)->unique('nation_raid_personal_reward_idem_unique');
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['event_id', 'character_id_snapshot', 'reward_key'],
                'nation_raid_personal_reward_unique',
            );
            $table->index(['event_id', 'status', 'character_id_snapshot'], 'nation_raid_personal_reward_status_idx');
        });

        Schema::create('nation_raid_nation_rewards', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->string('nation_name_snapshot', 80);
            $table->string('reward_key', 64);
            $table->string('status', 16)->default('pending');
            $table->json('reward_snapshot');
            $table->json('balance_after_snapshot')->nullable();
            $table->unsignedBigInteger('nation_resource_transaction_id')->nullable();
            $table->foreign('nation_resource_transaction_id', 'nation_raid_nation_resource_tx_fk')
                ->references('id')
                ->on('nation_resource_transactions')
                ->nullOnDelete();
            $table->char('idempotency_key', 64)->unique('nation_raid_nation_reward_idem_unique');
            $table->dateTime('claimed_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['event_id', 'nation_id_snapshot', 'reward_key'],
                'nation_raid_nation_reward_unique',
            );
            $table->index(['event_id', 'status', 'nation_id_snapshot'], 'nation_raid_nation_reward_status_idx');
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('nation_raid_events') && DB::table('nation_raid_events')->exists()) {
            throw new RuntimeException(
                '国家対抗レイドのイベント履歴が記録済みのためrollbackできません。公開gateをOFFにし、forward migrationで復旧してください。',
            );
        }

        Schema::dropIfExists('nation_raid_nation_rewards');
        Schema::dropIfExists('nation_raid_personal_rewards');
        Schema::dropIfExists('nation_raid_coordination_participants');
        Schema::dropIfExists('nation_raid_daily_lineage_snapshots');
        Schema::dropIfExists('nation_raid_battle_results');
        Schema::dropIfExists('nation_raid_daily_usages');
        Schema::dropIfExists('nation_raid_participations');
        Schema::dropIfExists('nation_raid_boss_cycles');
        Schema::dropIfExists('nation_raid_events');
        Schema::dropIfExists('competition_event_coordinators');
    }
};

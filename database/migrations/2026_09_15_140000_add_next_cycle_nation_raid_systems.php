<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('nation_raid_events', function (Blueprint $table): void {
            $table->dateTime('preparation_frozen_at')->nullable();
            $table->string('result_type', 16)->nullable();
            $table->unsignedSmallInteger('result_progress_bps')->nullable();
            $table->json('result_snapshot')->nullable();
            $table->char('result_hash', 64)->nullable();
        });

        Schema::table('nation_raid_participations', function (Blueprint $table): void {
            $table->boolean('is_recently_active_snapshot')->default(false);
            $table->unsignedTinyInteger('readiness_percent_snapshot')->default(0);
            $table->boolean('raid_benefits_held_snapshot')->default(false);
            $table->unsignedTinyInteger('free_sortie_daily_grant_snapshot')->default(3);
            $table->unsignedTinyInteger('free_sortie_balance_cap_snapshot')->default(9);
            $table->unsignedSmallInteger('free_sortie_balance')->default(0);
            $table->unsignedTinyInteger('free_sortie_last_granted_day')->default(0);
            $table->unsignedInteger('free_sorties_used')->default(0);
            $table->unsignedInteger('free_sorties_refunded')->default(0);
        });

        Schema::table('nation_raid_battle_results', function (Blueprint $table): void {
            $table->string('sortie_cost_type', 24)->nullable();
            $table->unsignedSmallInteger('stamina_cost')->nullable();
            $table->index(['event_id', 'sortie_cost_type', 'status'], 'nation_raid_cost_type_idx');
        });

        Schema::table('nation_raid_personal_rewards', function (Blueprint $table): void {
            // Existing rewards remain finalization-based and claimable through completed events.
            $table->string('availability_type', 24)->default('finalization');
            $table->dateTime('available_at')->nullable();
            $table->index(['event_id', 'availability_type', 'status'], 'nation_raid_reward_availability_idx');
        });

        Schema::create('nation_raid_nation_preparations', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->string('nation_name_snapshot', 80);
            $table->unsignedSmallInteger('reference_active_count');
            $table->unsignedInteger('contribution_target');
            $table->unsignedInteger('contribution_count')->default(0);
            $table->unsignedTinyInteger('readiness_percent')->default(0);
            $table->unsignedTinyInteger('earned_daily_free_grant')->default(3);
            $table->unsignedTinyInteger('earned_free_balance_cap')->default(9);
            $table->unsignedTinyInteger('applied_daily_free_grant')->default(3);
            $table->unsignedTinyInteger('applied_free_balance_cap')->default(9);
            $table->boolean('benefits_held')->default(false);
            $table->dateTime('frozen_at');
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'nation_id_snapshot'], 'nation_raid_preparation_nation_unique');
            $table->index(['nation_id', 'event_id'], 'nation_raid_preparation_live_nation_idx');
        });

        Schema::create('nation_raid_preparation_members', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('preparation_id')->constrained('nation_raid_nation_preparations')->cascadeOnDelete();
            $table->unsignedBigInteger('account_id_snapshot');
            $table->unsignedBigInteger('character_id_snapshot');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->unsignedTinyInteger('contribution_count')->default(0);
            $table->timestamps();

            $table->unique(['event_id', 'character_id_snapshot'], 'nation_raid_preparation_member_unique');
            $table->unique(['event_id', 'account_id_snapshot'], 'nation_raid_preparation_account_unique');
            $table->index(['event_id', 'nation_id_snapshot'], 'nation_raid_preparation_member_nation_idx');
        });

        Schema::create('nation_raid_preparation_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('preparation_id')->constrained('nation_raid_nation_preparations')->cascadeOnDelete();
            $table->foreignId('preparation_member_id')->constrained('nation_raid_preparation_members')->cascadeOnDelete();
            $table->unsignedBigInteger('battle_log_id');
            $table->unsignedTinyInteger('preparation_day');
            $table->date('contributed_on');
            $table->timestamps();

            $table->unique('battle_log_id', 'nation_raid_preparation_battle_unique');
            $table->unique(
                ['event_id', 'preparation_member_id', 'preparation_day'],
                'nation_raid_preparation_daily_unique',
            );
        });

        Schema::create('nation_raid_invasion_damages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('event_id')->constrained('nation_raid_events')->cascadeOnDelete();
            $table->foreignId('preparation_id')->nullable()->constrained('nation_raid_nation_preparations')->nullOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->foreignId('nation_id')->nullable()->constrained('nations')->nullOnDelete();
            $table->string('nation_name_snapshot', 80);
            $table->unsignedSmallInteger('reference_active_count');
            $table->unsignedSmallInteger('result_progress_bps');
            $table->unsignedTinyInteger('readiness_percent');
            $table->unsignedSmallInteger('effective_participant_count');
            $table->unsignedTinyInteger('participation_percent');
            $table->unsignedTinyInteger('base_damage');
            $table->unsignedTinyInteger('readiness_mitigation');
            $table->unsignedTinyInteger('participation_mitigation');
            $table->unsignedTinyInteger('final_damage');
            $table->unsignedInteger('reconstruction_required');
            $table->unsignedInteger('reconstruction_completed')->default(0);
            $table->string('status', 16)->default('active');
            $table->json('result_snapshot');
            $table->char('result_hash', 64);
            $table->dateTime('recovered_at')->nullable();
            $table->timestamps();

            $table->unique(['event_id', 'nation_id_snapshot'], 'nation_raid_invasion_nation_unique');
            $table->index(['nation_id', 'status'], 'nation_raid_invasion_outstanding_idx');
        });

        Schema::create('nation_raid_reconstruction_contributions', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('invasion_damage_id')->constrained('nation_raid_invasion_damages')->cascadeOnDelete();
            $table->unsignedBigInteger('nation_id_snapshot');
            $table->unsignedBigInteger('character_id_snapshot');
            $table->foreignId('character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->unsignedBigInteger('battle_log_id');
            $table->unsignedTinyInteger('amount')->default(1);
            $table->date('contributed_on');
            $table->timestamps();

            $table->unique('battle_log_id', 'nation_raid_reconstruction_battle_unique');
            $table->index(
                ['nation_id_snapshot', 'character_id_snapshot', 'contributed_on'],
                'nation_raid_reconstruction_daily_idx',
            );
        });
    }

    public function down(): void
    {
        throw_if(
            DB::table('nation_raid_events')->whereNotNull('preparation_frozen_at')->exists()
                || DB::table('nation_raid_nation_preparations')->exists()
                || DB::table('nation_raid_preparation_contributions')->exists()
                || DB::table('nation_raid_reconstruction_contributions')->exists()
                || DB::table('nation_raid_invasion_damages')->exists()
                || DB::table('nation_raid_events')->whereNotNull('result_type')->exists(),
            RuntimeException::class,
            '次回レイドの兵站・侵攻・復興履歴を失わないよう、forward migrationで復旧してください。',
        );

        Schema::dropIfExists('nation_raid_reconstruction_contributions');
        Schema::dropIfExists('nation_raid_invasion_damages');
        Schema::dropIfExists('nation_raid_preparation_contributions');
        Schema::dropIfExists('nation_raid_preparation_members');
        Schema::dropIfExists('nation_raid_nation_preparations');

        Schema::table('nation_raid_personal_rewards', function (Blueprint $table): void {
            $table->dropIndex('nation_raid_reward_availability_idx');
            $table->dropColumn(['availability_type', 'available_at']);
        });
        Schema::table('nation_raid_battle_results', function (Blueprint $table): void {
            $table->dropIndex('nation_raid_cost_type_idx');
            $table->dropColumn(['sortie_cost_type', 'stamina_cost']);
        });
        Schema::table('nation_raid_participations', fn (Blueprint $table) => $table->dropColumn([
            'is_recently_active_snapshot', 'readiness_percent_snapshot', 'raid_benefits_held_snapshot',
            'free_sortie_daily_grant_snapshot', 'free_sortie_balance_cap_snapshot', 'free_sortie_balance',
            'free_sortie_last_granted_day', 'free_sorties_used', 'free_sorties_refunded',
        ]));
        Schema::table('nation_raid_events', fn (Blueprint $table) => $table->dropColumn([
            'preparation_frozen_at', 'result_type', 'result_progress_bps', 'result_snapshot', 'result_hash',
        ]));
    }
};

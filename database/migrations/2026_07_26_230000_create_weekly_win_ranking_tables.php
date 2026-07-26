<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('weekly_win_ranking_seasons', function (Blueprint $table): void {
            $table->id();
            $table->string('season_key', 10)->unique();
            $table->dateTime('week_started_at');
            $table->dateTime('week_ended_at');
            $table->unsignedInteger('participant_count')->default(0);
            $table->unsignedInteger('rewarded_count')->default(0);
            $table->unsignedBigInteger('total_free_kiseki')->default(0);
            $table->dateTime('finalized_at')->nullable();
            $table->timestamps();

            $table->index(
                ['finalized_at', 'week_started_at'],
                'weekly_win_seasons_finalized_started_idx'
            );
        });

        Schema::create('weekly_win_ranking_records', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('weekly_win_ranking_seasons')
                ->cascadeOnDelete();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->unsignedInteger('wins');
            $table->unsignedInteger('rank');
            $table->unsignedSmallInteger('reward_free_kiseki')->default(0);
            $table->string('badge_key', 50)->nullable();
            $table->string('badge_label', 80)->nullable();
            $table->boolean('is_reward_eligible')->default(false);
            $table->dateTime('rewarded_at')->nullable();
            $table->timestamps();

            $table->unique(
                ['season_id', 'character_id'],
                'weekly_win_records_season_character_unique'
            );
            $table->index(['season_id', 'rank'], 'weekly_win_records_season_rank_idx');
            $table->index(
                ['character_id', 'badge_key'],
                'weekly_win_records_character_badge_idx'
            );
        });

        Schema::table('battle_logs', function (Blueprint $table): void {
            $table->index(
                ['result', 'created_at', 'character_id'],
                'battle_logs_result_created_character_idx'
            );
        });
    }

    public function down(): void
    {
        if (Schema::hasTable('battle_logs')) {
            Schema::table('battle_logs', function (Blueprint $table): void {
                $table->dropIndex('battle_logs_result_created_character_idx');
            });
        }

        Schema::dropIfExists('weekly_win_ranking_records');
        Schema::dropIfExists('weekly_win_ranking_seasons');
    }
};

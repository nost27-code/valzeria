<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('six_hero_daily_usages', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('character_id')->constrained()->cascadeOnDelete();
            $table->date('usage_date');
            $table->unsignedTinyInteger('official_attempts')->default(0);
            $table->json('official_attempts_by_room')->nullable();
            $table->timestamps();

            $table->unique(
                ['character_id', 'usage_date'],
                'six_hero_daily_usages_character_date_uq',
            );
        });

        Schema::create('six_hero_battle_logs', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('season_id')
                ->constrained('six_hero_seasons')
                ->cascadeOnDelete();
            $table->string('room_key', 32);
            $table->string('battle_mode', 16);
            $table->string('status', 16);
            $table->foreignId('attacker_id')
                ->constrained('characters')
                ->cascadeOnDelete();
            $table->foreignId('defender_id')
                ->constrained('characters')
                ->cascadeOnDelete();
            $table->integer('attacker_rank_at_start');
            $table->integer('defender_rank_at_start');
            $table->boolean('is_attacker_win')->nullable();
            $table->boolean('rank_changed')->nullable();
            $table->integer('attacker_old_rank')->nullable();
            $table->integer('attacker_new_rank')->nullable();
            $table->integer('defender_old_rank')->nullable();
            $table->integer('defender_new_rank')->nullable();
            $table->unsignedSmallInteger('turn_count')->nullable();
            $table->decimal('attacker_hp_ratio', 9, 8)->nullable();
            $table->decimal('defender_hp_ratio', 9, 8)->nullable();
            $table->unsignedTinyInteger('daily_attempt_number');
            $table->dateTime('started_at');
            $table->dateTime('resolved_at')->nullable();
            $table->dateTime('completed_at')->nullable();
            $table->dateTime('failed_at')->nullable();
            $table->string('failure_code', 64)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(
                ['season_id', 'room_key', 'created_at'],
                'six_hero_battle_logs_season_room_created_idx',
            );
            $table->index(
                ['attacker_id', 'created_at'],
                'six_hero_battle_logs_attacker_created_idx',
            );
            $table->index(
                ['defender_id', 'created_at'],
                'six_hero_battle_logs_defender_created_idx',
            );
            $table->index('status', 'six_hero_battle_logs_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('six_hero_battle_logs');
        Schema::dropIfExists('six_hero_daily_usages');
    }
};

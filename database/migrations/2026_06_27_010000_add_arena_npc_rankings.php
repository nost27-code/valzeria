<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAYER_TOP_PROTECTED_RANK = 10;

    private const EXCLUDED_NPC_IDS = [
        8, 12, 17, 26, 29, 37, 38, 39, 45, 48, 50, 57,
    ];

    public function up(): void
    {
        Schema::create('arena_npc_rankings', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('npc_id')->unique();
            $table->integer('rank')->unique();
            $table->integer('level')->default(1);
            $table->string('battle_profile', 32)->default('balanced');
            $table->integer('wins')->default(0);
            $table->integer('losses')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('arena_npc_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_id')->constrained('characters')->cascadeOnDelete();
            $table->foreignId('arena_npc_ranking_id')->constrained('arena_npc_rankings')->cascadeOnDelete();
            $table->unsignedBigInteger('npc_id');
            $table->boolean('is_attacker_win');
            $table->integer('attacker_old_rank');
            $table->integer('attacker_new_rank');
            $table->integer('defender_old_rank');
            $table->integer('defender_new_rank');
            $table->timestamps();

            $table->index(['attacker_id', 'created_at'], 'arena_npc_logs_attacker_created_idx');
        });

        Schema::create('arena_npc_auto_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('attacker_npc_ranking_id')->constrained('arena_npc_rankings')->cascadeOnDelete();
            $table->unsignedBigInteger('attacker_npc_id');
            $table->string('defender_type', 16);
            $table->foreignId('defender_character_id')->nullable()->constrained('characters')->nullOnDelete();
            $table->foreignId('defender_npc_ranking_id')->nullable()->constrained('arena_npc_rankings')->nullOnDelete();
            $table->unsignedBigInteger('defender_npc_id')->nullable();
            $table->boolean('is_attacker_win');
            $table->integer('attacker_old_rank');
            $table->integer('attacker_new_rank');
            $table->integer('defender_old_rank');
            $table->integer('defender_new_rank');
            $table->timestamps();

            $table->index(['defender_character_id', 'created_at'], 'arena_npc_auto_logs_defender_character_idx');
            $table->index(['attacker_npc_ranking_id', 'created_at'], 'arena_npc_auto_logs_attacker_npc_idx');
        });

        $npcs = DB::table('npc_master')
            ->where('is_active', true)
            ->whereNotIn('npc_rank', ['legend', 'hero'])
            ->whereNotIn('npc_id', self::EXCLUDED_NPC_IDS)
            ->orderByRaw("CASE npc_rank WHEN 'skilled' THEN 1 WHEN 'common' THEN 2 ELSE 9 END")
            ->orderBy('sort_order')
            ->orderBy('npc_id')
            ->get(['npc_id', 'npc_rank']);

        if ($npcs->isEmpty()) {
            return;
        }

        $npcCount = $npcs->count();
        $now = now();

        DB::transaction(function () use ($npcs, $npcCount, $now): void {
            DB::table('arena_rankings')
                ->where('rank', '>', self::PLAYER_TOP_PROTECTED_RANK)
                ->orderByDesc('rank')
                ->get(['id', 'rank'])
                ->each(function ($ranking) use ($npcCount): void {
                    DB::table('arena_rankings')
                        ->where('id', $ranking->id)
                        ->update(['rank' => (int) $ranking->rank + $npcCount]);
                });

            $profiles = ['physical', 'guard', 'speed', 'magical', 'balanced'];
            foreach ($npcs->values() as $index => $npc) {
                DB::table('arena_npc_rankings')->insert([
                    'npc_id' => (int) $npc->npc_id,
                    'rank' => self::PLAYER_TOP_PROTECTED_RANK + $index + 1,
                    'level' => $this->recommendedLevel((int) $npc->npc_id, (string) $npc->npc_rank),
                    'battle_profile' => $profiles[$index % count($profiles)],
                    'wins' => 0,
                    'losses' => 0,
                    'is_active' => true,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        });
    }

    public function down(): void
    {
        $npcCount = Schema::hasTable('arena_npc_rankings')
            ? DB::table('arena_npc_rankings')->count()
            : 0;

        Schema::dropIfExists('arena_npc_logs');
        Schema::dropIfExists('arena_npc_auto_logs');
        Schema::dropIfExists('arena_npc_rankings');

        if ($npcCount <= 0 || ! Schema::hasTable('arena_rankings')) {
            return;
        }

        DB::table('arena_rankings')
            ->where('rank', '>', self::PLAYER_TOP_PROTECTED_RANK + $npcCount)
            ->orderBy('rank')
            ->get(['id', 'rank'])
            ->each(function ($ranking) use ($npcCount): void {
                DB::table('arena_rankings')
                    ->where('id', $ranking->id)
                    ->update(['rank' => max(1, (int) $ranking->rank - $npcCount)]);
            });
    }

    private function recommendedLevel(int $npcId, string $rank): int
    {
        return match ($rank) {
            'hero' => max(42, min(50, 50 - ($npcId - 41))),
            'skilled' => max(32, min(40, 32 + (40 - $npcId))),
            default => max(18, min(28, 18 + (20 - $npcId))),
        };
    }
};

<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAYER_TOP_PROTECTED_RANK = 10;
    private const NPC_LOWER_ENTRY_FLOOR_RANK = 50;

    private const EXCLUDED_NPC_IDS = [
        8, 12, 17, 26, 29, 37, 38, 39, 45, 48, 50, 57,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('arena_npc_rankings') || ! Schema::hasTable('npc_master')) {
            return;
        }

        $activeNpcRankings = DB::table('arena_npc_rankings')
            ->join('npc_master', 'npc_master.npc_id', '=', 'arena_npc_rankings.npc_id')
            ->where('arena_npc_rankings.is_active', true)
            ->whereNotIn('npc_master.npc_rank', ['legend', 'hero'])
            ->whereNotIn('arena_npc_rankings.npc_id', self::EXCLUDED_NPC_IDS)
            ->orderByRaw("CASE npc_master.npc_rank WHEN 'skilled' THEN 1 WHEN 'common' THEN 2 ELSE 9 END")
            ->orderBy('npc_master.sort_order')
            ->orderBy('npc_master.npc_id')
            ->get([
                'arena_npc_rankings.id',
                'arena_npc_rankings.npc_id',
                'npc_master.npc_rank',
            ]);

        if ($activeNpcRankings->isEmpty()) {
            return;
        }

        $playerMaxRank = Schema::hasTable('arena_rankings')
            ? (int) (DB::table('arena_rankings')->max('rank') ?? 0)
            : 0;
        $startRank = max($playerMaxRank, self::PLAYER_TOP_PROTECTED_RANK, self::NPC_LOWER_ENTRY_FLOOR_RANK) + 1;
        $profiles = ['physical', 'guard', 'speed', 'magical', 'balanced'];

        DB::transaction(function () use ($activeNpcRankings, $startRank, $profiles): void {
            foreach ($activeNpcRankings as $ranking) {
                DB::table('arena_npc_rankings')
                    ->where('id', $ranking->id)
                    ->update(['rank' => -1000000 - (int) $ranking->id]);
            }

            foreach ($activeNpcRankings->values() as $index => $ranking) {
                DB::table('arena_npc_rankings')
                    ->where('id', $ranking->id)
                    ->update([
                        'rank' => $startRank + $index,
                        'level' => $this->recommendedLevel((int) $ranking->npc_id, (string) $ranking->npc_rank),
                        'battle_profile' => $profiles[$index % count($profiles)],
                        'updated_at' => now(),
                    ]);
            }
        });
    }

    public function down(): void
    {
        // Lower-rank NPC entry is the desired current arena balance.
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

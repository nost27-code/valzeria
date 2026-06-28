<?php

use Illuminate\Database\Migrations\Migration;
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
        if (! Schema::hasTable('arena_npc_rankings') || ! Schema::hasTable('arena_rankings') || ! Schema::hasTable('npc_master')) {
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
            ->get(['arena_npc_rankings.id', 'arena_npc_rankings.npc_id', 'npc_master.npc_rank']);

        $allNpcRankings = DB::table('arena_npc_rankings')
            ->join('npc_master', 'npc_master.npc_id', '=', 'arena_npc_rankings.npc_id')
            ->get(['arena_npc_rankings.id', 'arena_npc_rankings.npc_id', 'npc_master.npc_rank']);

        $playerRankings = DB::table('arena_rankings')
            ->orderBy('rank')
            ->orderBy('id')
            ->get(['id']);

        $profiles = ['physical', 'guard', 'speed', 'magical', 'balanced'];
        $activeNpcCount = $activeNpcRankings->count();

        DB::transaction(function () use ($allNpcRankings, $activeNpcRankings, $playerRankings, $profiles, $activeNpcCount): void {
            foreach ($allNpcRankings as $ranking) {
                DB::table('arena_npc_rankings')
                    ->where('id', $ranking->id)
                    ->update(['rank' => -1000000 - (int) $ranking->id]);
            }

            foreach ($playerRankings as $ranking) {
                DB::table('arena_rankings')
                    ->where('id', $ranking->id)
                    ->update(['rank' => -2000000 - (int) $ranking->id]);
            }

            foreach ($allNpcRankings as $ranking) {
                if ((string) $ranking->npc_rank === 'hero') {
                    DB::table('arena_npc_rankings')
                        ->where('id', $ranking->id)
                        ->update([
                            'rank' => 900000 + (int) $ranking->id,
                            'level' => $this->recommendedLevel((int) $ranking->npc_id, (string) $ranking->npc_rank),
                            'is_active' => false,
                            'updated_at' => now(),
                        ]);
                }
            }

            foreach ($activeNpcRankings->values() as $index => $ranking) {
                DB::table('arena_npc_rankings')
                    ->where('id', $ranking->id)
                    ->update([
                        'rank' => self::PLAYER_TOP_PROTECTED_RANK + $index + 1,
                        'level' => $this->recommendedLevel((int) $ranking->npc_id, (string) $ranking->npc_rank),
                        'battle_profile' => $profiles[$index % count($profiles)],
                        'is_active' => true,
                        'updated_at' => now(),
                    ]);
            }

            foreach ($playerRankings->values() as $index => $ranking) {
                $rank = $index < self::PLAYER_TOP_PROTECTED_RANK
                    ? $index + 1
                    : self::PLAYER_TOP_PROTECTED_RANK + $activeNpcCount + ($index - self::PLAYER_TOP_PROTECTED_RANK) + 1;

                DB::table('arena_rankings')
                    ->where('id', $ranking->id)
                    ->update(['rank' => $rank]);
            }
        });
    }

    public function down(): void
    {
        // Hero NPCs are intentionally held outside the active rankings for now.
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

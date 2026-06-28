<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('arena_npc_rankings') || ! Schema::hasTable('npc_master')) {
            return;
        }

        $rows = DB::table('arena_npc_rankings')
            ->join('npc_master', 'npc_master.npc_id', '=', 'arena_npc_rankings.npc_id')
            ->get([
                'arena_npc_rankings.id',
                'arena_npc_rankings.npc_id',
                'npc_master.npc_rank',
            ]);

        foreach ($rows as $row) {
            DB::table('arena_npc_rankings')
                ->where('id', $row->id)
                ->update([
                    'level' => $this->recommendedLevel((int) $row->npc_id, (string) $row->npc_rank),
                    'updated_at' => now(),
                ]);
        }
    }

    public function down(): void
    {
        // Display-level calibration is the desired current arena NPC balance.
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

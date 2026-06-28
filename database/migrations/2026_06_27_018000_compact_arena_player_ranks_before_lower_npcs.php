<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const PLAYER_TOP_PROTECTED_RANK = 10;
    private const NPC_LOWER_ENTRY_FLOOR_RANK = 50;

    public function up(): void
    {
        if (! Schema::hasTable('arena_rankings')) {
            return;
        }

        $players = DB::table('arena_rankings')
            ->orderBy('rank')
            ->orderBy('id')
            ->get(['id']);

        $activeNpcs = Schema::hasTable('arena_npc_rankings')
            ? DB::table('arena_npc_rankings')
                ->where('is_active', true)
                ->orderBy('rank')
                ->orderBy('id')
                ->get(['id'])
            : collect();

        DB::transaction(function () use ($players, $activeNpcs): void {
            foreach ($players as $player) {
                DB::table('arena_rankings')
                    ->where('id', $player->id)
                    ->update(['rank' => -2000000 - (int) $player->id]);
            }

            foreach ($activeNpcs as $npc) {
                DB::table('arena_npc_rankings')
                    ->where('id', $npc->id)
                    ->update(['rank' => -1000000 - (int) $npc->id]);
            }

            foreach ($players->values() as $index => $player) {
                DB::table('arena_rankings')
                    ->where('id', $player->id)
                    ->update(['rank' => $index + 1]);
            }

            $npcStartRank = max($players->count(), self::PLAYER_TOP_PROTECTED_RANK, self::NPC_LOWER_ENTRY_FLOOR_RANK) + 1;
            foreach ($activeNpcs->values() as $index => $npc) {
                DB::table('arena_npc_rankings')
                    ->where('id', $npc->id)
                    ->update(['rank' => $npcStartRank + $index]);
            }
        });
    }

    public function down(): void
    {
        // Compact player ranks before lower NPC entries is the desired ordering.
    }
};

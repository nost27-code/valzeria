<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const EXCLUDED_NPC_IDS = [
        8, 12, 17, 26, 29, 37, 38, 39, 45, 48, 50, 57,
    ];

    public function up(): void
    {
        if (! Schema::hasTable('arena_npc_rankings') || ! Schema::hasTable('npc_master')) {
            return;
        }

        $excludedNpcIds = DB::table('npc_master')
            ->whereIn('npc_rank', ['legend', 'hero'])
            ->pluck('npc_id')
            ->map(fn ($id): int => (int) $id)
            ->merge(self::EXCLUDED_NPC_IDS)
            ->unique()
            ->values();

        if ($excludedNpcIds->isNotEmpty()) {
            $excludedRankingIds = DB::table('arena_npc_rankings')
                ->whereIn('npc_id', $excludedNpcIds)
                ->pluck('id');

            if ($excludedRankingIds->isNotEmpty() && Schema::hasTable('arena_npc_logs')) {
                DB::table('arena_npc_logs')
                    ->whereIn('arena_npc_ranking_id', $excludedRankingIds)
                    ->delete();
            }

            DB::table('arena_npc_rankings')
                ->whereIn('npc_id', $excludedNpcIds)
                ->delete();
        }

        $this->compactRanks();
    }

    public function down(): void
    {
        // Removed NPC entries are intentionally not restored; the first arena NPC
        // migration can seed a fresh environment, while this migration only prunes
        // unsuitable live rankers.
    }

    private function compactRanks(): void
    {
        $entries = collect();

        if (Schema::hasTable('arena_rankings')) {
            DB::table('arena_rankings')
                ->orderBy('rank')
                ->get(['id', 'rank'])
                ->each(fn ($row) => $entries->push([
                    'type' => 'player',
                    'id' => (int) $row->id,
                    'rank' => (int) $row->rank,
                ]));
        }

        DB::table('arena_npc_rankings')
            ->where('is_active', true)
            ->orderBy('rank')
            ->get(['id', 'rank'])
            ->each(fn ($row) => $entries->push([
                'type' => 'npc',
                'id' => (int) $row->id,
                'rank' => (int) $row->rank,
            ]));

        $ordered = $entries
            ->sortBy('rank')
            ->values();

        DB::transaction(function () use ($ordered): void {
            foreach ($ordered as $entry) {
                $table = $entry['type'] === 'npc' ? 'arena_npc_rankings' : 'arena_rankings';
                DB::table($table)
                    ->where('id', $entry['id'])
                    ->update(['rank' => -1 * $entry['id'] - ($entry['type'] === 'npc' ? 1000000 : 0)]);
            }

            foreach ($ordered as $index => $entry) {
                $table = $entry['type'] === 'npc' ? 'arena_npc_rankings' : 'arena_rankings';
                DB::table($table)
                    ->where('id', $entry['id'])
                    ->update(['rank' => $index + 1]);
            }
        });
    }
};

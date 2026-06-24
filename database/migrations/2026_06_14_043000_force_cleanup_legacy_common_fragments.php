<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const TARGET_NAME = '装備の欠片';
    private const LEGACY_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];

    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        $targetId = DB::table('materials')->where('material_code', self::TARGET_CODE)->value('id');
        if (!$targetId) {
            return;
        }

        DB::table('materials')
            ->where('id', $targetId)
            ->update([
                'name' => self::TARGET_NAME,
                'updated_at' => now(),
            ]);

        $legacyIds = DB::table('materials')
            ->whereIn('material_code', self::LEGACY_CODES)
            ->where('id', '!=', $targetId)
            ->pluck('id')
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($legacyIds->isEmpty()) {
            return;
        }

        $this->mergeCharacterMaterials((int) $targetId, $legacyIds);
        $this->mergeMaterialDrops((int) $targetId, $legacyIds);
        $this->moveExplorationLootLogs((int) $targetId, $legacyIds);

        DB::table('materials')->whereIn('id', $legacyIds)->delete();
    }

    public function down(): void
    {
        // One-way cleanup: legacy common fragments should stay unified.
    }

    private function mergeCharacterMaterials(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        foreach (DB::table('character_materials')->whereIn('material_id', $legacyIds)->get() as $row) {
            $existing = DB::table('character_materials')
                ->where('character_id', $row->character_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('character_materials')
                    ->where('id', $existing->id)
                    ->update([
                        'quantity' => (int) $existing->quantity + (int) $row->quantity,
                        'updated_at' => now(),
                    ]);
                DB::table('character_materials')->where('id', $row->id)->delete();
                continue;
            }

            DB::table('character_materials')
                ->where('id', $row->id)
                ->update([
                    'material_id' => $targetId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function mergeMaterialDrops(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        foreach (DB::table('material_drops')->whereIn('material_id', $legacyIds)->get() as $drop) {
            $existing = DB::table('material_drops')
                ->where('enemy_id', $drop->enemy_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('material_drops')
                    ->where('id', $existing->id)
                    ->update([
                        'drop_rate' => max((float) $existing->drop_rate, (float) $drop->drop_rate),
                        'drop_first_clear_only' => (bool) $existing->drop_first_clear_only && (bool) $drop->drop_first_clear_only,
                        'drop_timing' => $existing->drop_timing ?: $drop->drop_timing,
                        'is_active' => (bool) $existing->is_active || (bool) $drop->is_active,
                        'updated_at' => now(),
                    ]);
                DB::table('material_drops')->where('id', $drop->id)->delete();
                continue;
            }

            DB::table('material_drops')
                ->where('id', $drop->id)
                ->update([
                    'material_id' => $targetId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function moveExplorationLootLogs(int $targetId, $legacyIds): void
    {
        if (!Schema::hasTable('exploration_loot_logs')) {
            return;
        }

        DB::table('exploration_loot_logs')
            ->whereIn('material_id', $legacyIds)
            ->update([
                'material_id' => $targetId,
                'updated_at' => now(),
            ]);
    }
};

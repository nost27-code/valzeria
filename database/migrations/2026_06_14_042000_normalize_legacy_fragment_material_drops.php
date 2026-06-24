<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TARGET_CODE = 'MAT_EQUIPMENT_FRAGMENT';
    private const LEGACY_CODES = ['WEV0001', '5001', 'ACC0001', 'MAT_WEAPON_FRAGMENT'];

    public function up(): void
    {
        if (!Schema::hasTable('materials') || !Schema::hasTable('material_drops')) {
            return;
        }

        $targetId = DB::table('materials')->where('material_code', self::TARGET_CODE)->value('id');
        $legacyIds = DB::table('materials')
            ->whereIn('material_code', self::LEGACY_CODES)
            ->pluck('id')
            ->filter(fn ($id) => (int) $id !== (int) $targetId)
            ->values();

        if (!$targetId || $legacyIds->isEmpty()) {
            return;
        }

        $drops = DB::table('material_drops')->whereIn('material_id', $legacyIds)->get();
        foreach ($drops as $drop) {
            $existing = DB::table('material_drops')
                ->where('enemy_id', $drop->enemy_id)
                ->where('material_id', $targetId)
                ->first();

            if ($existing) {
                DB::table('material_drops')
                    ->where('id', $existing->id)
                    ->update([
                        'drop_rate' => max((float) $existing->drop_rate, (float) $drop->drop_rate),
                        'is_active' => (bool) $existing->is_active || (bool) $drop->is_active,
                        'updated_at' => now(),
                    ]);

                DB::table('material_drops')->where('id', $drop->id)->delete();
            } else {
                DB::table('material_drops')
                    ->where('id', $drop->id)
                    ->update([
                        'material_id' => $targetId,
                        'updated_at' => now(),
                    ]);
            }
        }
    }

    public function down(): void
    {
        // One-way cleanup: legacy fragment drops should stay unified.
    }
};

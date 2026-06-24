<?php

use App\Support\NormalDropMaterialConsolidator;
use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasTable('materials')) {
            return;
        }

        DB::transaction(function (): void {
            $this->upsertCommonMaterials();
            $this->mergeLegacyNormalMaterials();
            $this->reactivateBossUniqueDrops();
        });
    }

    public function down(): void
    {
        // 旧素材から共通素材へ統合した所持数とドロップを安全に戻すことはできないため、
        // downでは新素材の自動削除や所持数の巻き戻しを行わない。
    }

    private function upsertCommonMaterials(): void
    {
        $now = now();

        foreach (NormalDropMaterialConsolidator::definitions() as $code => $definition) {
            DB::table('materials')->updateOrInsert(
                ['material_code' => $code],
                array_merge(
                    NormalDropMaterialConsolidator::payload($code),
                    [
                        'city_id' => $this->cityIdForRegion($code),
                        'dungeon_id' => null,
                        'source_enemy_id' => null,
                        'drop_rate' => 0,
                        'drop_first_clear_only' => false,
                        'drop_timing' => null,
                        'updated_at' => $now,
                        'created_at' => $now,
                    ]
                )
            );
        }
    }

    private function mergeLegacyNormalMaterials(): void
    {
        $materials = DB::table('materials')
            ->where('material_code', 'like', 'MAT____')
            ->where(function ($query): void {
                $query->whereNull('material_type')
                    ->orWhere('material_type', '')
                    ->orWhereNotIn('material_type', [
                        'boss_unique',
                        'sell_treasure',
                        'equipment_common',
                        'branch_evolution',
                        'brewing',
                    ]);
            })
            ->orderBy('id')
            ->get();

        foreach ($materials as $material) {
            if (!NormalDropMaterialConsolidator::isLegacyNormalCode((string) $material->material_code)) {
                continue;
            }

            $targetCode = NormalDropMaterialConsolidator::targetCodeFor(
                (string) $material->name,
                $material->city_id !== null ? (int) $material->city_id : null
            );
            $target = DB::table('materials')->where('material_code', $targetCode)->first();
            if (!$target || (int) $target->id === (int) $material->id) {
                continue;
            }

            $this->mergeOwnedMaterials((int) $material->id, (int) $target->id);
            $this->mergeMaterialDrops((int) $material->id, (int) $target->id);
            $this->markAsLegacy((int) $material->id, $targetCode);
        }
    }

    private function mergeOwnedMaterials(int $fromMaterialId, int $toMaterialId): void
    {
        if (!Schema::hasTable('character_materials')) {
            return;
        }

        foreach (DB::table('character_materials')->where('material_id', $fromMaterialId)->get() as $row) {
            $existing = DB::table('character_materials')
                ->where('character_id', $row->character_id)
                ->where('material_id', $toMaterialId)
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
                    'material_id' => $toMaterialId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function mergeMaterialDrops(int $fromMaterialId, int $toMaterialId): void
    {
        if (!Schema::hasTable('material_drops')) {
            return;
        }

        foreach (DB::table('material_drops')->where('material_id', $fromMaterialId)->get() as $drop) {
            $existing = DB::table('material_drops')
                ->where('enemy_id', $drop->enemy_id)
                ->where('material_id', $toMaterialId)
                ->first();

            if ($existing) {
                DB::table('material_drops')
                    ->where('id', $existing->id)
                    ->update([
                        'drop_rate' => max((float) $existing->drop_rate, (float) $drop->drop_rate),
                        'drop_first_clear_only' => (bool) $existing->drop_first_clear_only || (bool) $drop->drop_first_clear_only,
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
                    'material_id' => $toMaterialId,
                    'updated_at' => now(),
                ]);
        }
    }

    private function markAsLegacy(int $materialId, string $targetCode): void
    {
        DB::table('materials')
            ->where('id', $materialId)
            ->update([
                'material_type' => NormalDropMaterialConsolidator::TYPE_LEGACY,
                'category_id' => 'legacy_normal_drop',
                'category' => '旧通常素材',
                'main_use' => '共通素材へ統合済み',
                'npc_sale_price' => 0,
                'is_tradable' => false,
                'drop_rate' => 0,
                'drop_first_clear_only' => false,
                'drop_timing' => null,
                'obtain_method' => "共通素材 {$targetCode} へ統合済み。",
                'updated_at' => now(),
            ]);
    }

    private function reactivateBossUniqueDrops(): void
    {
        $bossMaterialIds = DB::table('materials')
            ->where('material_type', 'boss_unique')
            ->pluck('id')
            ->all();

        if ($bossMaterialIds === []) {
            return;
        }

        DB::table('materials')
            ->whereIn('id', $bossMaterialIds)
            ->update([
                'drop_rate' => 100,
                'drop_first_clear_only' => true,
                'drop_timing' => 'boss_clear',
                'updated_at' => now(),
            ]);

        if (Schema::hasTable('material_drops')) {
            DB::table('material_drops')
                ->whereIn('material_id', $bossMaterialIds)
                ->update([
                    'drop_rate' => 100,
                    'drop_first_clear_only' => true,
                    'drop_timing' => 'boss_clear',
                    'is_active' => true,
                    'updated_at' => now(),
                ]);
        }
    }

    private function cityIdForRegion(string $code): ?int
    {
        return match ($code) {
            'MAT_REGION_ARKREA_RAW' => 1,
            'MAT_REGION_TIDAL_PIECE' => 2,
            'MAT_REGION_WORLD_TREE_LEAF' => 3,
            'MAT_REGION_BLACK_IRON_PART' => 4,
            'MAT_REGION_ICE_CRYSTAL' => 5,
            'MAT_REGION_ANCIENT_SAND' => 6,
            'MAT_REGION_MAGIC_CRYSTAL' => 7,
            'MAT_REGION_ABYSS_FRAGMENT' => 8,
            'MAT_REGION_HEAVEN_FEATHER' => 9,
            default => null,
        };
    }
};
